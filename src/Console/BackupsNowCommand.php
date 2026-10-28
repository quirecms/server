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
    name: 'server:backups:now',
    description: 'Back up off the server now: one site, or (without one) everything the night does',
)]
final readonly class BackupsNowCommand
{
    public function __construct(
        private OffsiteBackups $backups,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'A site\'s short name; every site and the server when not given')]
        ?string $name = null,
    ): int {
        if (!$this->backups->configured()) {
            $io->error('Backups off the server aren\'t set up: "quire-server backups:setup".');

            return Command::FAILURE;
        }

        try {
            if (null !== $name) {
                $snapshot = $this->backups->backUpNow(new SiteName($name), Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line.'…'));
                $io->success(\sprintf('"%s" is backed up off the server (%s).', $name, substr($snapshot, 0, 8)));

                return Command::SUCCESS;
            }

            $outcome = $this->backups->nightly(Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] !== $outcome['failed']) {
            $io->error('Not backed up: '.implode(', ', $outcome['failed']).' ("quire-server operations" has the log).');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Backed up off the server: %s.', implode(', ', $outcome['backed_up'])));

        return Command::SUCCESS;
    }
}
