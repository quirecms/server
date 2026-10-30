<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Operation\OperationLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:busy',
    description: 'Whether an operation runs now (then it fails, naming them): "quire-server up" and "down" ask first, since they would end it',
)]
final readonly class BusyCommand
{
    public function __construct(
        private OperationLog $operations,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $running = $this->operations->running();

        if ([] === $running) {
            return Command::SUCCESS;
        }

        $io->writeln(implode(', ', $running));

        return Command::FAILURE;
    }
}
