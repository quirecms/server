<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Database\DatabaseAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:database-logins',
    description: 'Let the server\'s roles log in to its PostgreSQL from the server\'s network, as Docker has it now (the agent\'s start, before it logs in)',
)]
final readonly class DatabaseLoginsCommand
{
    public function __construct(
        private DatabaseAccess $access,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln($this->access->server() ? 'PostgreSQL: the server\'s logins from its network written again.' : 'PostgreSQL: the server\'s logins are as they should be.');

        return Command::SUCCESS;
    }
}
