<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Network\SiteLinks;
use Quire\Server\Registry\SiteRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'server:links', description: 'Put the front door and PostgreSQL back on every site\'s link (the agent does it every minute; "quire-server up" right away)', hidden: true)]
final readonly class LinksCommand
{
    public function __construct(
        private SiteRegistry $registry,
        private SiteLinks $links,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $done = $this->links->reconcile($this->registry->all());

        foreach ($done as $line) {
            $io->writeln($line);
        }

        if ([] === $done) {
            $io->writeln('Every site\'s link is as it should be.');
        }

        return Command::SUCCESS;
    }
}
