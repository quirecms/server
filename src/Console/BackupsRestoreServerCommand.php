<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\OffsiteBackups;
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

          <info>quire-server backups:restore-server --from-server=old-server</info>

        Then point each domain's DNS at this server. The sites have settings of their own (their
        database, password and secret): everyone signs in again.
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
    ): int {
        if (!$this->backups->configured()) {
            $io->error('Backups off the server aren\'t set up: "quire-server backups:setup --existing --host=<this server\'s name>" connects to the old server\'s.');

            return Command::FAILURE;
        }

        if (null === $fromServer) {
            $io->error('Name the old server: --from-server=<its QUIRE_SERVER_BACKUP_HOST>.');

            return Command::INVALID;
        }

        try {
            $manifest = $this->backups->manifest($fromServer);
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

        $outcomes = $this->backups->restoreServer($fromServer, Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line));
        $io->table(['Site', 'Outcome', ''], array_map(array_values(...), $outcomes));

        if (\in_array('failed', array_column($outcomes, 'outcome'), true)) {
            $io->error('Not every site was restored: restore those one by one ("backups:restore <site> --from-server='.$fromServer.' --as=<site> --domain=…"), after looking at why ("quire-server operations").');

            return Command::FAILURE;
        }

        $io->success('The sites are restored. Point their domains\' DNS at this server: their certificates come at the first visit.');

        return Command::SUCCESS;
    }
}
