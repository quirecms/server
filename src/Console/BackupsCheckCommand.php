<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\Restic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:backups:check',
    description: 'Check the backups off the server: their structure, and a part of their data read back',
    help: <<<'HELP'
        Every Sunday night, 5% of the data is read back. Reading it all (--read=100) downloads every
        backup: storage providers may charge for that.
        HELP,
)]
final readonly class BackupsCheckCommand
{
    public function __construct(
        private BackupSettings $settings,
        private Restic $restic,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'How much of the data to read back, in percent')]
        int $read = 5,
    ): int {
        if (!$this->settings->configured()) {
            $io->error('Backups off the server aren\'t set up: "quire-server backups:setup".');

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('  Checking the repository, and reading back %d%% of its data…', $read));
        $result = $this->restic->check($read);

        if (!$result->successful()) {
            $io->error(['The backups DON\'T check out:', trim($result->output."\n".$result->errorOutput)]);

            return Command::FAILURE;
        }

        $io->success('The backups check out.');

        return Command::SUCCESS;
    }
}
