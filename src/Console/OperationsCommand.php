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

#[AsCommand(name: 'server:operations', description: 'What was done on the server, and an operation\'s log')]
final readonly class OperationsCommand
{
    public function __construct(
        private OperationLog $operations,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'An operation\'s ID, to show its log')]
        ?string $id = null,
        #[Option(description: 'Only this site\'s')]
        ?string $site = null,
        #[Option(description: 'How many')]
        int $limit = 20,
    ): int {
        if (null !== $id) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id)) {
                $io->error('That isn\'t an operation\'s ID.');

                return Command::INVALID;
            }

            $io->writeln($this->operations->lines($id), SymfonyStyle::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $rows = $this->operations->latest(null === $site ? null : new SiteName($site), max(1, min(500, $limit)));
        $io->table(['ID', 'Started', 'What', 'Site', 'By', 'Outcome'], array_map(static fn (array $row): array => [
            $row['id'],
            substr($row['started_at'], 0, 19),
            $row['action'],
            $row['site'] ?? '',
            $row['requested_by'].' ('.$row['channel'].')',
            $row['status'].(null === $row['error'] ? '' : ': '.mb_strimwidth($row['error'], 0, 80, '…')),
        ], $rows));

        return Command::SUCCESS;
    }
}
