<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Operation\OperationLog;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:operations',
    description: 'What was done on the server, and an operation\'s log',
    help: <<<'HELP'
        The latest operations, newest first, with who asked and how each ended (times in UTC). Give an
        operation's ID (its first 8 characters are enough) for its whole log:

          <info>quire-server operations</info>
          <info>quire-server operations --site=jane</info>
          <info>quire-server operations 1a2b3c4d</info>
        HELP,
)]
final readonly class OperationsCommand
{
    public function __construct(
        private OperationLog $operations,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'An operation\'s ID (its first 8 characters are enough), to show its log')]
        ?string $id = null,
        #[Option(description: 'Only this site\'s')]
        ?string $site = null,
        #[Option(description: 'How many')]
        int $limit = 20,
    ): int {
        if (null !== $id) {
            $full = $this->operations->resolve(strtolower($id));

            if (null === $full) {
                $io->error('No operation has that ID (or more than one starts so): "quire-server operations" lists them.');

                return Command::INVALID;
            }

            $operation = array_find($this->operations->latest(null, 5000), static fn (array $row): bool => $row['id'] === $full);

            if (null !== $operation) {
                $io->writeln(\sprintf('%s%s, asked by %s (%s), %s: %s', $operation['action'], null === $operation['site'] ? '' : ' of '.$operation['site'], $operation['requested_by'], $operation['channel'], Time::utc($operation['started_at']), $operation['status']));

                if (null !== $operation['error']) {
                    $io->writeln($operation['error']);
                }

                $io->newLine();
            }

            $io->writeln($this->operations->lines($full), SymfonyStyle::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $rows = $this->operations->latest(null === $site ? null : new SiteName($site), max(1, min(500, $limit)));
        $io->table(['ID', 'Started (UTC)', 'What', 'Site', 'By', 'Outcome'], array_map(static fn (array $row): array => [
            substr($row['id'], 0, 8),
            substr(Time::utc($row['started_at']), 0, 16),
            $row['action'],
            $row['site'] ?? '',
            $row['requested_by'].' ('.$row['channel'].')',
            $row['status'].(null === $row['error'] ? '' : ': '.mb_strimwidth($row['error'], 0, 80, '…')),
        ], $rows));
        $io->writeln('"quire-server operations <ID>" shows one\'s whole log, and why it failed.');

        return Command::SUCCESS;
    }
}
