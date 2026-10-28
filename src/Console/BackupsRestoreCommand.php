<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Site\Domain;
use Quire\Server\Site\SiteName;
use Quire\Server\Update\UpdateFailed;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:backups:restore',
    description: 'Restore a site from one of its backups off the server',
    help: <<<'HELP'
        Gets the backup from off the server, backs the site up as it is, restores the backup (its
        database and files) and checks the site answers; if it doesn't, it's put back as it was.
        What changed on the site since that backup is lost: you're asked first.

          <info>quire-server backups:restore jane</info>                    its latest backup
          <info>quire-server backups:restore jane --snapshot=1a2b3c4d</info>  that one ("quire-server backups jane")

        As a new site, with its own name and domains (a copy, e.g. to try an update on), from this
        server's backups or another's in the same bucket (moving a site: its old server's
        QUIRE_SERVER_BACKUP_HOST). Its code and files are the backup's, its data restored; its settings
        are its own (its database, password and secret: everyone signs in again):

          <info>quire-server backups:restore jane --as=jane-copy --domain=copy.jane.example</info>
          <info>quire-server backups:restore jane --from-server=old-server --as=jane --domain=jane.example</info>

        A Git mode site's code is in its repository, not its backups: add it from there, then restore
        its data in place.
        HELP,
)]
final readonly class BackupsRestoreCommand
{
    public function __construct(
        private OffsiteBackups $backups,
    ) {
    }

    /**
     * @param list<string> $domain
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name, e.g. "jane"')]
        string $name,
        #[Option(description: 'The backup\'s ID, or "latest"')]
        string $snapshot = 'latest',
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
        #[Option(description: 'Restore it as a new site with this name, instead of over the site', name: 'as')]
        ?string $as = null,
        #[Option(description: 'With --as: a domain of the new site (repeatable); the first is the main one')]
        array $domain = [],
        #[Option(description: 'Another server\'s backups in the same bucket (its QUIRE_SERVER_BACKUP_HOST), with --as')]
        ?string $fromServer = null,
    ): int {
        if (!$this->backups->configured()) {
            $io->error('Backups off the server aren\'t set up: "quire-server backups:setup".');

            return Command::FAILURE;
        }

        if (null !== $as || null !== $fromServer || [] !== $domain) {
            return $this->asNew($io, $name, $snapshot, $as, $domain, $fromServer, $yes);
        }

        try {
            $site = new SiteName($name);

            if (!$yes && !$io->confirm(\sprintf('Restore "%s" from its %s backup off the server? What changed on it since is lost.', $site, $snapshot), false)) {
                $io->writeln('Nothing changed.');

                return Command::SUCCESS;
            }

            $restored = $this->backups->restore($site, $snapshot, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (UpdateFailed $e) {
            $io->error($e->restored
                ? ['Restoring it failed, and the site is as it was before:', $e->getMessage(), 'Its log: quire-server operations '.$e->operationId]
                : ['Restoring it failed, and so did putting the site back: it needs you.', $e->getMessage(), 'Its log (and where its backup is): quire-server operations '.$e->operationId]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('"%s" is as it was on %s (backup %s).', $site, $restored['time'], substr($restored['id'], 0, 8)));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $domains
     */
    private function asNew(SymfonyStyle $io, string $name, string $snapshot, ?string $as, array $domains, ?string $fromServer, bool $yes): int
    {
        try {
            $source = new SiteName($name);
            $target = new SiteName($as ?? throw new \InvalidArgumentException('Name the new site: --as=<name> (and its --domain).'));
            $domains = array_map(static fn (string $value): Domain => new Domain($value), $domains);

            if ([] === $domains) {
                throw new \InvalidArgumentException('Give the new site its domains: --domain=… (repeatable).');
            }

            if (!$yes && !$io->confirm(\sprintf('Add the site "%s" (%s) from the %s backup of "%s"%s?', $target, implode(', ', array_map(static fn (Domain $domain): string => $domain->unicode(), $domains)), $snapshot, $source, null === $fromServer ? '' : ' on '.$fromServer), true)) {
                $io->writeln('Nothing changed.');

                return Command::SUCCESS;
            }

            $restored = $this->backups->restoreAsNew($source, $snapshot, $target, $domains, $fromServer, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (\Throwable $e) {
            $io->error(['The site wasn\'t added (everything done was undone):', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success(\sprintf('"%s" runs, as "%s" was on %s (backup %s).', $target, $source, $restored['time'], substr($restored['id'], 0, 8)));
        $io->writeln(\sprintf('Point the DNS of %s at this server: its certificate comes at the first visit. Everyone signs in again (it has a secret of its own).', implode(', ', array_map(static fn (Domain $domain): string => $domain->unicode(), $domains))));

        return Command::SUCCESS;
    }
}
