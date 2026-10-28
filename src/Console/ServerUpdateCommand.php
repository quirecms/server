<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Release\ReleaseVerificationFailed;
use Quire\Server\Release\Version;
use Quire\Server\ServerUpdate\ServerUpdateFailed;
use Quire\Server\ServerUpdate\ServerUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:update',
    description: 'Update the server itself to a newer release (or undo its last update)',
    help: <<<'HELP'
        Gets the release and checks it as the installer does (its signature, by Quire Server's release
        workflow; its files' checksum; its image by digest), then: waits for operations on sites to
        finish, backs the server up (its files, settings and database), replaces it and checks each
        part is healthy. If anything fails, the server is put back as it was by itself. Sites keep
        running; they're offline only for the seconds the front door is replaced, when it changes.

          <info>quire-server update</info>                   the latest release
          <info>quire-server update --to=0.2.0</info>   that release
          <info>quire-server update --check</info>           only say whether there's a newer one
          <info>quire-server update --undo</info>            back to the release before the last update (within a week)
        HELP,
)]
final readonly class ServerUpdateCommand
{
    public function __construct(
        private ServerUpdater $updater,
        private bool $inUpdater,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The release to update to, e.g. "0.2.0" (the latest when not given)')]
        ?string $to = null,
        #[Option(description: 'Only say whether there\'s a newer release')]
        bool $check = false,
        #[Option(description: 'Go back to the release before the last update')]
        bool $undo = false,
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
    ): int {
        if (!$this->inUpdater) {
            $io->error('The server updates itself from its updater: run "quire-server update" on the machine.');

            return Command::FAILURE;
        }

        try {
            $asked = null === $to ? null : new Version($to);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if ($undo) {
            return $this->undo($io, $yes);
        }

        $running = $this->updater->running();

        try {
            $target = $this->updater->target($asked);
        } catch (\DomainException|ReleaseVerificationFailed $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$target instanceof Version) {
            $io->success(\sprintf('Quire Server %s runs: it\'s the latest release.', $running));

            return Command::SUCCESS;
        }

        $from = $running instanceof Version ? 'Quire Server '.$running : 'a build from a checkout';

        if ($check) {
            $io->writeln(\sprintf('Quire Server %s is available (this server runs %s): "quire-server update" installs it.', $target, $from));

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Updating from %s to Quire Server %s', $from, $target));

        if (!$yes && !$io->confirm('Update now? Sites keep running.', true)) {
            return Command::SUCCESS;
        }

        try {
            $this->updater->update($target, Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line));
        } catch (ServerUpdateFailed $e) {
            return $this->failed($io, 'The update failed', $e);
        }

        $io->success(\sprintf('Quire Server %s runs. "quire-server update --undo" goes back, within a week.', $target));

        return Command::SUCCESS;
    }

    private function undo(SymfonyStyle $io, bool $yes): int
    {
        $backup = $this->updater->undoable();

        if (!$backup instanceof \Quire\Server\ServerUpdate\ServerBackup) {
            $io->error('There\'s no update to undo (only the last one can be, for a week).');

            return Command::FAILURE;
        }

        $io->title(\sprintf('Undoing the update from %s to %s of %s', $backup->from, $backup->to, $backup->createdAt->format('Y-m-d H:i')));

        if ($this->updater->undoRestoresDatabase($backup)) {
            $io->warning('That update changed the server\'s database: its backup from before is restored, and what changed in it since (sites added or removed, the operations\' record) is lost.');
        }

        if (!$yes && !$io->confirm('Undo it now? Sites keep running.', false)) {
            return Command::SUCCESS;
        }

        try {
            $this->updater->undo($backup, Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line));
        } catch (ServerUpdateFailed $e) {
            return $this->failed($io, 'Undoing the update failed', $e);
        }

        $io->success(\sprintf('It runs as before the update (%s).', $backup->from));

        return Command::SUCCESS;
    }

    private function failed(SymfonyStyle $io, string $what, ServerUpdateFailed $e): int
    {
        $io->error($e->restored
            ? [$what.' and changed nothing: the server runs as it was.', $e->getMessage()]
            : [$what.', and so did putting the server back: it needs you.', $e->getMessage(), 'Its backup: '.$e->backup, 'The log: quire-server operations']);

        return Command::FAILURE;
    }
}
