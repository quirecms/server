<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:backups',
    description: 'The backups off the server: of a site, or the server\'s own',
    help: <<<'HELP'
          <info>quire-server backups jane</info>     the site's, newest first, with their IDs (for "backups:restore")
          <info>quire-server backups server</info>   the server's own (its registry, domains and deploy keys)
        HELP,
)]
final readonly class BackupsCommand
{
    public function __construct(
        private OffsiteBackups $backups,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'A site\'s short name, or "server"')]
        string $name,
    ): int {
        if (!$this->backups->configured()) {
            $io->error('There are no backups off the server yet: "quire-server backups:setup" sets them up.');

            return Command::FAILURE;
        }

        try {
            $snapshots = $this->backups->list('server' === $name ? null : new SiteName($name));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $snapshots) {
            $io->writeln(\sprintf('No backup of %s off the server yet.', 'server' === $name ? 'the server' : '"'.$name.'"'));

            return Command::SUCCESS;
        }

        $io->table(['ID', 'Made'], array_map(static fn (array $snapshot): array => [substr($snapshot['id'], 0, 8), $snapshot['time']], $snapshots));

        return Command::SUCCESS;
    }
}
