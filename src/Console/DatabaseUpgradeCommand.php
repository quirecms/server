<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\ServerDatabase\DatabaseUpgrade;
use Quire\Server\ServerDatabase\DatabaseUpgradeFailed;
use Quire\Server\ServerDatabase\DatabaseUpgrader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:database:upgrade',
    description: 'Upgrade the server\'s PostgreSQL to a newer major version (or undo it)',
    help: <<<'HELP'
        A planned step, with downtime for the sites that use the server's PostgreSQL (sites with their
        own aren't touched): every site is backed up off the server first (when that's set up), the
        sites are stopped, the new major is started beside the old one and every database copied into
        it and checked, then the server switches to it and the sites start again. If anything fails,
        the server is put back on the old major by itself. The old major's data stays on the server:
        "--undo" goes back to it within a week, "--clean" removes it.

          <info>quire-server database:upgrade --check</info>     which major runs, and which this release can upgrade to
          <info>quire-server database:upgrade</info>             to the newest major this release runs
          <info>quire-server database:upgrade --to=19</info>     to that major
          <info>quire-server database:upgrade --undo</info>      back to the old major (what changed since is lost)
          <info>quire-server database:upgrade --clean</info>     remove the data of majors that don't run
          <info>quire-server database:upgrade --recover</info>   put the server back after an upgrade that was cut short
        HELP,
)]
final readonly class DatabaseUpgradeCommand
{
    public function __construct(
        private DatabaseUpgrader $upgrader,
        private bool $inUpdater,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The major version to upgrade to, e.g. "19" (the newest this release runs when not given)')]
        ?string $to = null,
        #[Option(description: 'Only say which major runs and which this release can upgrade to')]
        bool $check = false,
        #[Option(description: 'Go back to the major before the last upgrade')]
        bool $undo = false,
        #[Option(description: 'Remove the data of the majors that don\'t run')]
        bool $clean = false,
        #[Option(description: 'Put the server back after an upgrade that was cut short')]
        bool $recover = false,
        #[Option(description: 'Don\'t back up off the server first')]
        bool $noBackup = false,
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
    ): int {
        if (!$this->inUpdater) {
            $io->error('PostgreSQL is upgraded from the updater: run "quire-server database:upgrade" on the machine.');

            return Command::FAILURE;
        }

        if (null !== $to && 1 !== preg_match('/^[1-9]\d{1,2}\z/', $to)) {
            $io->error(\sprintf('"%s" isn\'t a PostgreSQL major version (e.g. 19).', $to));

            return Command::INVALID;
        }

        $latest = $this->upgrader->latest();

        if ($recover) {
            return $this->recover($io, $latest);
        }

        if ($latest instanceof DatabaseUpgrade && $latest->unfinished()) {
            $io->error(\sprintf('An upgrade of PostgreSQL %d to %d was cut short (%s, %s): put the server back first with "quire-server database:upgrade --recover".', $latest->from, $latest->to, $latest->createdAt->format('Y-m-d H:i'), $latest->state));

            return Command::FAILURE;
        }

        try {
            return match (true) {
                $check => $this->check($io, $latest),
                $undo => $this->undo($io, $yes),
                $clean => $this->clean($io, $yes),
                default => $this->upgrade($io, null === $to ? null : (int) $to, !$noBackup, $yes),
            };
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (DatabaseUpgradeFailed $e) {
            $io->error($e->restored
                ? ['It failed and the server runs as it was, on the old major.', $e->getMessage()]
                : ['It failed, and so did putting the server back: it needs you.', $e->getMessage(), 'The log: quire-server operations']);

            return Command::FAILURE;
        }
    }

    private function check(SymfonyStyle $io, ?DatabaseUpgrade $latest): int
    {
        [$running, $version] = $this->upgrader->running();
        $images = $this->upgrader->images();
        $io->writeln(\sprintf('PostgreSQL %s runs. This release runs PostgreSQL %s (new servers get %d).', $version, implode(', ', $images->majors()), $images->default));
        $newest = $this->upgrader->newest();
        $io->writeln(null === $newest ? 'It\'s the newest this release runs.' : \sprintf('"quire-server database:upgrade --to=%d" upgrades it.', $newest));

        if ($latest instanceof DatabaseUpgrade && $latest->undoable(new \DateTimeImmutable())) {
            $io->writeln(\sprintf('Upgraded from PostgreSQL %d on %s: "--undo" goes back until %s.', $latest->from, $latest->createdAt->format('Y-m-d H:i'), $latest->createdAt->add(new \DateInterval(DatabaseUpgrade::UNDOABLE_FOR))->format('Y-m-d H:i')));
        }

        $others = $this->upgrader->clean(Requester::CHANNEL, Requester::name(), static function (): void {}, dryRun: true);

        if ([] !== $others) {
            $io->writeln(\sprintf('Data of PostgreSQL %s is still on the server, besides %d\'s: "--clean" removes it.', implode(', ', $others), $running));
        }

        return Command::SUCCESS;
    }

    private function upgrade(SymfonyStyle $io, ?int $to, bool $backUp, bool $yes): int
    {
        $to ??= $this->upgrader->newest();
        [, $version] = $this->upgrader->running();

        if (null === $to) {
            $io->success(\sprintf('PostgreSQL %s runs: it\'s the newest this release runs.', $version));

            return Command::SUCCESS;
        }

        $plan = $this->upgrader->plan();
        $io->title(\sprintf('Upgrading PostgreSQL %s to %d', $version, $to));
        $io->writeln([
            \sprintf('%d databases are copied into PostgreSQL %d and checked. The sites that use it are offline meanwhile (visitors see "unavailable"): minutes for most, longer for large databases.', \count($plan['databases']), $to),
            'The old data stays on the server: "--undo" goes back to it within a week.',
            '',
        ]);

        if (!$yes && !$io->confirm('Upgrade now?', false)) {
            return Command::SUCCESS;
        }

        $this->upgrader->upgrade($to, Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line), $backUp);
        $io->success(\sprintf('PostgreSQL %d runs, and the sites again. "--undo" goes back within a week; "--clean" removes the old data once you\'re sure.', $to));

        return Command::SUCCESS;
    }

    private function undo(SymfonyStyle $io, bool $yes): int
    {
        $upgrade = $this->upgrader->undoable();

        if (!$upgrade instanceof DatabaseUpgrade) {
            $io->error('There\'s no upgrade to undo (only the last one can be, for a week).');

            return Command::FAILURE;
        }

        $io->title(\sprintf('Undoing the upgrade from PostgreSQL %d to %d of %s', $upgrade->from, $upgrade->to, $upgrade->createdAt->format('Y-m-d H:i')));
        $io->warning(\sprintf('PostgreSQL %d\'s data is used as the upgrade left it: what every site and the server wrote since (content, sign-ins, sites added or removed) is lost. It stays in PostgreSQL %d\'s data until "--clean".', $upgrade->from, $upgrade->to));

        if (!$yes && !$io->confirm('Undo it now? Sites using it are offline for a moment.', false)) {
            return Command::SUCCESS;
        }

        $this->upgrader->undo($upgrade, Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line));
        $io->success(\sprintf('PostgreSQL %d runs, as before the upgrade.', $upgrade->from));

        return Command::SUCCESS;
    }

    private function clean(SymfonyStyle $io, bool $yes): int
    {
        $others = $this->upgrader->clean(Requester::CHANNEL, Requester::name(), static function (): void {}, dryRun: true);

        if ([] === $others) {
            $io->success('Only the data of the PostgreSQL that runs is on the server.');

            return Command::SUCCESS;
        }

        if ($this->upgrader->undoable() instanceof DatabaseUpgrade) {
            $io->warning('The last upgrade can\'t be undone after this.');
        }

        if (!$yes && !$io->confirm(\sprintf('Remove the data of PostgreSQL %s for good?', implode(', ', $others)), false)) {
            return Command::SUCCESS;
        }

        $removed = $this->upgrader->clean(Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line));
        $io->success(\sprintf('Removed: PostgreSQL %s\'s data.', implode(', ', $removed)));

        return Command::SUCCESS;
    }

    private function recover(SymfonyStyle $io, ?DatabaseUpgrade $latest): int
    {
        if (!$latest instanceof DatabaseUpgrade || !$latest->unfinished()) {
            $io->success('No upgrade was cut short: there\'s nothing to put back.');

            return Command::SUCCESS;
        }

        try {
            $this->upgrader->recover($latest, Requester::CHANNEL, Requester::name(), static fn (string $line) => $io->writeln('  '.$line));
        } catch (DatabaseUpgradeFailed $e) {
            $io->error(['Putting the server back failed: it needs you.', $e->getMessage(), 'The log: quire-server operations']);

            return Command::FAILURE;
        }

        $io->success(DatabaseUpgrade::UNDOING === $latest->state
            ? \sprintf('The undo is finished: PostgreSQL %d runs, as before the upgrade.', $latest->from)
            : \sprintf('The server runs as before the upgrade, on PostgreSQL %d. Upgrade again when you\'re ready.', $latest->from));

        return Command::SUCCESS;
    }
}
