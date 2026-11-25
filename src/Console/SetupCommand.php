<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Database\SiteDatabases;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'server:setup', description: 'Harden the server\'s PostgreSQL (run by the agent\'s container at every start; changes nothing twice). Every site\'s compose.server.yaml is the agent\'s own start\'s: an update of the server holds the lock it needs until the agent runs', hidden: true)]
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
