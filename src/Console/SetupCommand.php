<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Database\SiteDatabases;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:setup',
    description: 'Harden the server\'s PostgreSQL (run by the agent at every start; changes nothing twice)',
)]
final readonly class SetupCommand
{
    public function __construct(
        private SiteDatabases $databases,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->databases->harden('quire_server');
        $io->writeln('PostgreSQL: only allowed roles may connect to each database.');

        return Command::SUCCESS;
    }
}
