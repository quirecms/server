<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:backups',
    description: 'The backups off the server: of a site, or the server\'s own',
    help: <<<'HELP'
          <info>quire-server backups jane</info>     the site's, newest first, with their IDs (for "backups:restore")
          <info>quire-server backups server</info>   the server's own (its registry, domains and deploy keys)
          <info>quire-server backups jane --from-server=old-server</info>   another server's, in the same bucket
        HELP,
)]
final readonly class BackupsCommand
{
    public function __construct(
        private OffsiteBackups $backups,
        private \Quire\Server\Backup\LocalBackups $local,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'A site\'s short name, or "server"')]
        string $name,
        #[Option(description: 'Another server\'s backups in the same bucket (its QUIRE_SERVER_BACKUP_HOST)')]
        ?string $fromServer = null,
        #[Option(description: 'Its backups on the server instead (before an update, a restore, an undo; its final one)')]
        bool $local = false,
    ): int {
        if ($local) {
            try {
                $site = new SiteName($name);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }

            $backups = $this->local->of($site);

            if ([] === $backups) {
                $io->writeln(\sprintf('No backup of "%s" on the server.', $site));

                return Command::SUCCESS;
            }

            $io->table(['File', 'Before', 'Made (UTC)', 'Size'], array_map(static fn (array $backup): array => [$backup['file'], $backup['kind'], $backup['made']->format('Y-m-d H:i:s'), \sprintf('%.1f MB', $backup['size'] / 1024 ** 2)], $backups));
            $io->writeln(\sprintf('"quire-server backups:restore %s --file=<file>" puts it back as one has it.', $site));

            return Command::SUCCESS;
        }

        if (!$this->backups->configured()) {
            $io->error('There are no backups off the server yet: "quire-server backups:setup" sets them up.');

            return Command::FAILURE;
        }

        try {
            $snapshots = $this->backups->list('server' === $name ? null : new SiteName($name), $fromServer);
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
