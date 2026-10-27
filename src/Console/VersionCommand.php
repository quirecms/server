<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:version',
    description: 'Which Quire Server this is: its release, and the agent\'s image',
)]
final readonly class VersionCommand
{
    public function __construct(
        private string $version,
        private string $image,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln(\sprintf('Quire Server %s', $this->version));
        $io->writeln('' === $this->image ? 'Agent: built from a checkout' : \sprintf('Agent: %s', $this->image));

        return Command::SUCCESS;
    }
}
