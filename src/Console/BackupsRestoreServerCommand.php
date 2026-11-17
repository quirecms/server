<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Operation\SiteManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:backups:restore-server',
    description: 'Restore every site of another server from its backups off the server (a server that replaces it)',
    help: <<<'HELP'
        On a new server, connected to the old one's backups ("backups:setup --existing
        --host=new-server"): every site the old server had when it was last backed up is restored
        here, from its latest backup, with the same name and domains and its update policy. A Git
        mode site comes from its repository, with its deploy key from the old server's backup. Sites
        whose name this server has already are left out; one that fails doesn't stop the others.

          <info>quire-server backups:restore-server --from-server=old-server --signed-by=ed25519:…</info>

        The old server's public key for its backups (shown by "quire-server status" on it, and when its
        backups were set up) proves each backup is its own: anyone with the backups' password could
        put others in the bucket. Backups made before servers signed theirs need --unsigned.

        Then point each domain's DNS at this server. The sites have settings of their own (their
        database, password and secret): everyone signs in again.

        The sites send no email, so a rehearsal can't email the old server's users. For a real move,
        --keep-mailer keeps the mailers their backups had; --server-mailer gives them this server's.
        The summary says which mailer each site has. Their Google Analytics is off (--keep-analytics
        keeps it), and a site whose stored files are on storage outside it, such as a bucket, isn't
        restored while the old server's site may still use it (--shared-storage, once it's gone).
        HELP,
)]
final readonly class BackupsRestoreServerCommand
{
    public function __construct(
        private OffsiteBackups $backups,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The old server\'s name in the backups (its QUIRE_SERVER_BACKUP_HOST)')]
        ?string $fromServer = null,
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
        #[Option(description: 'The old server\'s public key for its backups ("quire-server status" showed it there)')]
        ?string $signedBy = null,
        #[Option(description: 'Restore backups this server can\'t verify (made before servers signed theirs): only ones you know the origin of')]
        bool $unsigned = false,
        #[Option(description: 'Keep the mailers the sites\' backups had, rather than this server\'s (or none): only for a real move, never a test or a rehearsal while the old server still runs')]
        bool $keepMailer = false,
        #[Option(description: 'Give them this server\'s mailer: they email the old server\'s users from this server')]
        bool $serverMailer = false,
        #[Option(description: 'Keep their Google Analytics: their visits count as the old server\'s sites\' (a real move)')]
        bool $keepAnalytics = false,
        #[Option(description: 'Restore sites whose stored files are on storage outside them (a bucket): the old server\'s sites use it too, so only once those are gone')]
        bool $sharedStorage = false,
    ): int {
        if ($keepMailer && $serverMailer) {
            $io->error('--keep-mailer or --server-mailer, not both.');

            return Command::INVALID;
        }

        if (!$this->backups->configured()) {
            $io->error('Backups off the server aren\'t set up: "quire-server backups:setup --existing --host=<this server\'s name>" connects to the old server\'s.');

            return Command::FAILURE;
        }

        if (null === $fromServer) {
            $io->error('Name the old server: --from-server=<its QUIRE_SERVER_BACKUP_HOST>.');

            return Command::INVALID;
        }

        try {
            $manifest = $this->backups->manifest($fromServer, $signedBy, $unsigned, static fn (string $line) => $io->writeln('  '.$line));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Only to show what will be restored; restoring reads it again.
        new \Symfony\Component\Filesystem\Filesystem()->remove($manifest['into']);

        if ([] === $manifest['sites']) {
            $io->success(\sprintf('"%s" had no site when it was last backed up (%s).', $fromServer, $manifest['time']));

            return Command::SUCCESS;
        }

        $io->table(['Site', 'Domains', 'From'], array_map(static fn (array $site): array => [$site['name'], implode(', ', $site['domains']), null === $site['git'] ? 'its backup' : 'its repository, and its backup'], $manifest['sites']));

        if (!$yes && !$io->confirm(\sprintf('Restore these %d site(s) of "%s" (as it was backed up on %s) on this server?', \count($manifest['sites']), $fromServer, $manifest['time']), true)) {
            $io->writeln('Nothing changed.');

            return Command::SUCCESS;
        }

        $outcomes = $this->backups->restoreServer($fromServer, Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line), $signedBy, $unsigned, $this->mailer($keepMailer, $serverMailer), $keepAnalytics, $sharedStorage);
        $io->table(['Site', 'Outcome', ''], array_map(array_values(...), $outcomes));

        if (\in_array('failed', array_column($outcomes, 'outcome'), true)) {
            $io->error('Not every site was restored: restore those one by one ("backups:restore <site> --from-server='.$fromServer.' --as=<site> --domain=…"), after looking at why ("quire-server operations").');

            return Command::FAILURE;
        }

        $io->success('The sites are restored. Point their domains\' DNS at this server: their certificates come at the first visit.');

        return Command::SUCCESS;
    }

    /**
     * The new site's mailer, as asked: none unless one of the options says otherwise.
     */
    private function mailer(bool $keepMailer, bool $serverMailer): string
    {
        if ($keepMailer && $serverMailer) {
            throw new \InvalidArgumentException('--keep-mailer or --server-mailer, not both.');
        }

        return match (true) {
            $keepMailer => SiteManager::MAILER_BACKUP,
            $serverMailer => SiteManager::MAILER_SERVER,
            default => SiteManager::MAILER_NONE,
        };
    }
}
