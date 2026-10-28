<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\OffsiteBackups;
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
        HELP,
)]
final readonly class BackupsRestoreCommand
{
    public function __construct(
        private OffsiteBackups $backups,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name, e.g. "jane"')]
        string $name,
        #[Option(description: 'The backup\'s ID, or "latest"')]
        string $snapshot = 'latest',
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
    ): int {
        if (!$this->backups->configured()) {
            $io->error('Backups off the server aren\'t set up: "quire-server backups:setup".');

            return Command::FAILURE;
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
}
