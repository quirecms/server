<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\BackupLedger;
use Quire\Server\Backup\BackupSigning;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:backups:new-key',
    description: 'Start a new key for signing this server\'s backups, in place of the one it had',
    help: <<<'HELP'
        Only when the server's key is gone for good (e.g. its file was deleted, and no backup of the
        server has it) or may be known to someone else. Backups signed with the old key stay restorable
        here; other servers that restore this one's backups need the new public key (--signed-by), and
        refuse what's signed with it until they have it.
        HELP,
)]
final readonly class BackupsNewKeyCommand
{
    public function __construct(
        private BackupSigning $signing,
        private BackupLedger $ledger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
    ): int {
        $old = $this->ledger->signingKey();

        if (null !== $old) {
            $io->warning(['This server signs its backups with '.$old.'.', 'Other servers that trust it will refuse what the new key signs, until they\'re given its public key.']);
        }

        if (!$yes && !$io->confirm('Start a new key now?', false)) {
            $io->writeln('Nothing changed.');

            return Command::SUCCESS;
        }

        $new = $this->signing->startOver();
        $io->success(['The server signs its backups with a new key from now on.', 'Its public key: '.$new]);
        $io->writeln('Keep it with the backups\' password, somewhere other than this server, and give it to the servers that restore this one\'s backups.');

        return Command::SUCCESS;
    }
}
