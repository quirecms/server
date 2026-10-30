<?php

declare(strict_types=1);

namespace Quire\Server\ServerDatabase;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Process\ProcessFailed;
use Quire\Server\Registry\SiteLock;
use Quire\Server\ServerUpdate\ServerFolder;
use Quire\Server\ServerUpdate\ServerStack;

/**
 * Upgrades the server's PostgreSQL to a newer major version, a deliberate step with planned downtime
 * for the sites that use it (sites with their own PostgreSQL aren't touched). It runs in the updater:
 *
 * check (the release runs that major, space for a copy, nothing it can't copy) → back every site up
 * off the server, when that's set up → wait for operations on sites to finish, stop the agent and the
 * sites using the server's PostgreSQL → start the new major beside the old one, on the same volume, in
 * a folder of its own → copy the roles and every database into it with the new major's tools → check
 * both have the same roles, rights, objects and row counts → switch server.env to the new major and
 * start it → start the sites and the agent again.
 *
 * The old major's data isn't touched: until the switch, a failure removes the new one and the server
 * runs as it was; after it, the old data stays for "--undo" (a week) until "--clean". Each step is
 * written down first (DatabaseUpgrade), so one cut short is put back by the next run.
 */
final readonly class DatabaseUpgrader
{
    private const int ROLLBACK_WAIT = 300;

    /** Room for the copy beside the old data: its size, a quarter more, and a GiB for its write-ahead log */
    private const float ROOM = 1.25;
    private const int ROOM_EXTRA = 1 << 30;

    public function __construct(
        private ServerFolder $folder,
        private ServerStack $stack,
        private DatabaseContainers $containers,
        private SiteLock $lock,
        private Connection $connection,
        private OperationLog $operations,
        private BackupSettings $backups,
        private string $root,
        private int $wait = 300,
        private \Closure $pause = sleep(...),
    ) {
    }

    public function images(): PostgresImages
    {
        return PostgresImages::of($this->folder->path);
    }

    /**
     * The newest upgrade, or null when there's been none.
     */
    public function latest(): ?DatabaseUpgrade
    {
        return DatabaseUpgrade::latest($this->upgrades());
    }

    /**
     * The major that runs, and its version ("18.6").
     *
     * @return array{int, string}
     */
    public function running(): array
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                // "180006 18.6", or "180006 18.6 (Debian 18.6-1)" from some builds
                $version = explode(' ', trim($this->query('postgres', "SELECT current_setting('server_version_num') || ' ' || current_setting('server_version')")).'  ');

                if (ctype_digit($version[0])) {
                    return [intdiv((int) $version[0], 10000), $version[1]];
                }
            } catch (ProcessFailed) {
                // Starting, or restarting
            }

            if ($attempt >= 30) {
                throw new \RuntimeException('The server\'s PostgreSQL doesn\'t answer.');
            }

            ($this->pause)(2);
        }
    }

    /**
     * The newest major this release runs that's newer than the one that runs, or null.
     */
    public function newest(): ?int
    {
        [$running] = $this->running();
        $newer = array_filter($this->images()->majors(), static fn (int $major): bool => $major > $running);

        return [] === $newer ? null : max($newer);
    }

    /**
     * What an upgrade would copy: the databases and the bytes they take.
     *
     * @return array{databases: list<string>, bytes: int}
     */
    public function plan(): array
    {
        $databases = [];

        foreach (array_filter(explode("\n", $this->query('postgres', DatabaseCheck::DATABASES)), static fn (string $row): bool => '' !== trim($row)) as $row) {
            [$name, $connectable] = explode('|', $row.'|');

            // Ours are site_<name> and quire_server: anything else is refused rather than guessed at.
            if (1 !== preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $name)) {
                throw new \DomainException(\sprintf('The server\'s PostgreSQL has a database named "%s": only plain names (a-z, 0-9, _) are copied. Rename it or move it elsewhere first.', $name));
            }

            if ('true' !== $connectable) {
                throw new \DomainException(\sprintf('The database "%s" doesn\'t allow connections: it can\'t be copied. Allow them, or drop it, first.', $name));
            }

            $databases[] = $name;
        }

        if ('0' !== trim($this->query('postgres', DatabaseCheck::POSTGRES_DATABASE))) {
            throw new \DomainException('PostgreSQL\'s own database "postgres" holds tables: Quire Server doesn\'t copy it. Move them to a database of their own first.');
        }

        $bytes = trim($this->query('postgres', DatabaseCheck::SIZE));

        return ['databases' => $databases, 'bytes' => is_numeric($bytes) ? (int) $bytes : 0];
    }

    /**
     * @param \Closure(string): void $say
     *
     * @throws \DomainException      When it can't be done (nothing changed)
     * @throws DatabaseUpgradeFailed
     */
    public function upgrade(int $to, string $channel, string $requestedBy, \Closure $say, bool $backUp = true): void
    {
        $lines = [];
        $log = static function (string $line) use (&$lines, $say): void {
            $lines[] = $line;
            $say($line);
        };
        $parameters = ['to' => (string) $to];

        try {
            $this->checkFinished();
            $images = $this->images();
            [$from, $version] = $this->running();
            $parameters['from'] = (string) $from;

            if ($to <= $from) {
                throw new \DomainException(\sprintf('The server runs PostgreSQL %s already.', $version));
            }

            if (!$images->supports($from)) {
                throw new \DomainException(\sprintf('This release doesn\'t run PostgreSQL %d, which the server runs: update the server first.', $from));
            }

            $image = $images->image($to);
            $plan = $this->plan();
            $log(\sprintf('PostgreSQL %s runs; upgrading it to %d (%d databases, %s)', $version, $to, \count($plan['databases']), $this->bytes($plan['bytes'])));
            $log(\sprintf('Getting PostgreSQL %d', $to));
            $this->stack->pull($image);
            $volume = $this->containers->volume();
            $free = $this->containers->free($image, $volume);
            $needed = (int) ($plan['bytes'] * self::ROOM) + self::ROOM_EXTRA;

            if ($free < $needed) {
                throw new \DomainException(\sprintf('The copy needs about %s on the server and %s is left: make room first (the old data stays until the upgrade is cleaned up).', $this->bytes($needed), $this->bytes($free)));
            }

            if (!$backUp) {
                $log('Not backed up off the server first (asked so): the old data stays on this server, as the way back');
            } elseif ($this->backups->configured()) {
                $log('Backing up every site and the server off the server first');
                $this->stack->backUpOffsite($this->folder, static function (string $output) use ($log): void {
                    foreach (explode("\n", trim($output)) as $line) {
                        if ('' !== trim($line)) {
                            $log('  '.trim($line));
                        }
                    }
                });
            } else {
                $log('Backups off the server aren\'t set up ("quire-server backups:setup"): the old data stays on this server, as the way back');
            }
        } catch (\Throwable $e) {
            $this->record('server.database-upgrade', $parameters, $channel, $requestedBy, $lines, $e);

            throw $e;
        }

        if (!$this->lock->lockServer()) {
            throw new \DomainException('An operation on a site runs: try again when it\'s finished ("quire-server operations").');
        }

        $upgrade = new DatabaseUpgrade($this->upgradeDirectory(), $from, $to, DatabaseUpgrade::COPYING, [], new \DateTimeImmutable());

        try {
            $upgrade->save();
            $log('Stopping the agent');
            $this->stack->stopAgent($this->folder);
            $upgrade = $upgrade->with(DatabaseUpgrade::COPYING, $this->containers->clients());
            $log(\sprintf('Stopping the sites that use it (%d containers): visitors see "unavailable" until they\'re back', \count($upgrade->stopped)));
            $this->containers->stop($upgrade->stopped);
            $this->checkQuiet();

            if (\in_array($to, $this->containers->majorsOnVolume($image, $volume), true)) {
                $log(\sprintf('Removing PostgreSQL %d\'s data from an earlier attempt', $to));
                $this->containers->removeMajor($image, $volume, $to);
            }

            $log(\sprintf('Starting PostgreSQL %d beside it', $to));
            $this->containers->startBeside($image, $volume, $to, $this->wait);
            $this->containers->copy($plan['databases'], static function (string $output) use ($log): void {
                foreach (explode("\n", trim($output)) as $line) {
                    if ('' !== trim($line)) {
                        $log(trim($line));
                    }
                }
            });
            $log('Checking the copy against the original');
            $counts = $this->check($plan['databases']);
            $log(\sprintf('The same in both: the roles and their rights, %d databases, %d tables, %d rows', \count($plan['databases']), $counts['tables'], $counts['rows']));
            $this->containers->removeBeside();

            $log(\sprintf('Switching to PostgreSQL %d', $to));
            $upgrade = $upgrade->with(DatabaseUpgrade::SWITCHED);
            $this->switchTo($to, $images);
            [, $version] = $this->running();
            $log(\sprintf('PostgreSQL %s runs', $version));
        } catch (\Throwable $e) {
            $log('It failed: '.$e->getMessage());
            $failure = $this->rollBack($upgrade, $images, DatabaseUpgrade::FAILED, $log, $e);
            $this->record('server.database-upgrade', $parameters, $channel, $requestedBy, $lines, $failure);

            throw $failure;
        } finally {
            $this->unlock();
        }

        try {
            $this->restart($upgrade, $log);
            $upgrade->with(DatabaseUpgrade::DONE);
        } catch (\Throwable $e) {
            $upgrade->with(DatabaseUpgrade::DONE);
            $failure = new DatabaseUpgradeFailed(\sprintf('PostgreSQL %d runs with every database, but starting the rest again failed: %s', $to, $e->getMessage()), restored: false, previous: $e);
            $this->record('server.database-upgrade', $parameters, $channel, $requestedBy, $lines, $failure);

            throw $failure;
        }

        $this->record('server.database-upgrade', $parameters, $channel, $requestedBy, $lines, null);
    }

    /**
     * The last upgrade, while it can be undone (a week, and the old major's data still there).
     */
    public function undoable(): ?DatabaseUpgrade
    {
        $latest = $this->latest();

        return $latest instanceof DatabaseUpgrade && $latest->undoable(new \DateTimeImmutable()) ? $latest : null;
    }

    /**
     * Back to the old major's data as the upgrade left it: what changed since is lost (the new
     * major's data stays until "--clean").
     *
     * @param \Closure(string): void $say
     *
     * @throws \DomainException      When it can't be done (nothing changed)
     * @throws DatabaseUpgradeFailed
     */
    public function undo(DatabaseUpgrade $upgrade, string $channel, string $requestedBy, \Closure $say): void
    {
        $lines = [];
        $log = static function (string $line) use (&$lines, $say): void {
            $lines[] = $line;
            $say($line);
        };
        $parameters = ['from' => (string) $upgrade->to, 'to' => (string) $upgrade->from];

        try {
            $images = $this->images();
            [$running] = $this->running();

            if ($running !== $upgrade->to || !$images->supports($upgrade->from)) {
                throw new \DomainException(\sprintf('The server runs PostgreSQL %d, and this release %s PostgreSQL %d: the upgrade can\'t be undone.', $running, $images->supports($upgrade->from) ? 'runs' : 'doesn\'t run', $upgrade->from));
            }

            if (!\in_array($upgrade->from, $this->containers->majorsOnVolume($images->image($running), $this->containers->volume()), true)) {
                throw new \DomainException(\sprintf('PostgreSQL %d\'s data isn\'t there any more: the upgrade can\'t be undone.', $upgrade->from));
            }
        } catch (\Throwable $e) {
            $this->record('server.database-undo', $parameters, $channel, $requestedBy, $lines, $e);

            throw $e;
        }

        if (!$this->lock->lockServer()) {
            throw new \DomainException('An operation on a site runs: try again when it\'s finished ("quire-server operations").');
        }

        $log(\sprintf('Undoing the upgrade from PostgreSQL %d to %d (%s)', $upgrade->from, $upgrade->to, $upgrade->createdAt->format('Y-m-d H:i')));

        try {
            $upgrade = $upgrade->with(DatabaseUpgrade::UNDOING, []);
            $log('Stopping the agent');
            $this->stack->stopAgent($this->folder);
            $upgrade = $upgrade->with(DatabaseUpgrade::UNDOING, $this->containers->clients());
            $log(\sprintf('Stopping the sites that use it (%d containers)', \count($upgrade->stopped)));
            $this->containers->stop($upgrade->stopped);
            $log(\sprintf('Switching back to PostgreSQL %d', $upgrade->from));
            $this->switchTo($upgrade->from, $images);
            $this->restart($upgrade, $log);
            $upgrade->with(DatabaseUpgrade::UNDONE);
        } catch (\Throwable $e) {
            $log('It failed: '.$e->getMessage());
            // Forward again: the state before the undo, PostgreSQL's newer major.
            $failure = $this->rollBack(new DatabaseUpgrade($upgrade->directory, $upgrade->to, $upgrade->from, DatabaseUpgrade::UNDOING, $upgrade->stopped, $upgrade->createdAt), $images, DatabaseUpgrade::DONE, $log, $e, $upgrade);
            $this->record('server.database-undo', $parameters, $channel, $requestedBy, $lines, $failure);

            throw $failure;
        } finally {
            $this->unlock();
        }

        $log(\sprintf('PostgreSQL %d runs, as before the upgrade', $upgrade->from));
        $this->record('server.database-undo', $parameters, $channel, $requestedBy, $lines, null);
    }

    /**
     * Finishes an upgrade (or undo) that was cut short: back to the old major while the copy was made,
     * forward on the new one once it was switched to. From the switch on, the sites may have run on the
     * new major (they start by themselves when the machine does) and written to it: going back would
     * lose that, and the old major's data has none of it.
     *
     * @param \Closure(string): void $say
     *
     * @throws DatabaseUpgradeFailed
     */
    public function recover(DatabaseUpgrade $upgrade, string $channel, string $requestedBy, \Closure $say): void
    {
        $lines = [];
        $log = static function (string $line) use (&$lines, $say): void {
            $lines[] = $line;
            $say($line);
        };
        $images = $this->images();

        if (DatabaseUpgrade::SWITCHED === $upgrade->state) {
            $log(\sprintf('Upgrading PostgreSQL %d to %d was cut short after the switch: finishing it on PostgreSQL %2$d, which the sites may have written to since', $upgrade->from, $upgrade->to));

            try {
                $this->containers->removeBeside();

                if ($this->runningMajor() !== $upgrade->to || $this->folder->env()->get('COMPOSE_FILE') !== $images->composeFile($this->folder->env()->get('COMPOSE_FILE') ?? 'compose.yaml', $upgrade->to)) {
                    $this->switchTo($upgrade->to, $images);
                }

                $this->restart($upgrade, $log);
            } catch (\Throwable $e) {
                $log('Finishing it failed: '.$e->getMessage());
                $failure = new DatabaseUpgradeFailed(\sprintf('Finishing the upgrade on PostgreSQL %d failed: %s', $upgrade->to, $e->getMessage()), restored: false, previous: $e);
                $this->record('server.database-recover', ['from' => (string) $upgrade->from, 'to' => (string) $upgrade->to], $channel, $requestedBy, $lines, $failure);

                throw $failure;
            }

            $upgrade->with(DatabaseUpgrade::DONE);
            $log(\sprintf('It runs on PostgreSQL %d', $upgrade->to));
            $this->record('server.database-recover', ['from' => (string) $upgrade->from, 'to' => (string) $upgrade->to], $channel, $requestedBy, $lines, null);

            return;
        }

        $undoing = DatabaseUpgrade::UNDOING === $upgrade->state;
        $log(\sprintf('%s PostgreSQL %d to %d was cut short (%s): finishing it as %s', $undoing ? 'Undoing the upgrade from' : 'Upgrading', $upgrade->from, $upgrade->to, $upgrade->state, $undoing ? 'undone' : 'not done'));
        $failure = $this->rollBack($upgrade, $images, $undoing ? DatabaseUpgrade::UNDONE : DatabaseUpgrade::FAILED, $log, null);
        $this->record('server.database-recover', ['from' => (string) $upgrade->from, 'to' => (string) $upgrade->to], $channel, $requestedBy, $lines, $failure->restored ? null : $failure);

        if (!$failure->restored) {
            throw $failure;
        }
    }

    /**
     * Removes the data of every major that doesn't run (the old one after an upgrade, the new one
     * after an undo): an upgrade can't be undone after.
     *
     * @param \Closure(string): void $say
     *
     * @return list<int> The majors whose data was removed
     */
    public function clean(string $channel, string $requestedBy, \Closure $say, bool $dryRun = false): array
    {
        $lines = [];
        $log = static function (string $line) use (&$lines, $say): void {
            $lines[] = $line;
            $say($line);
        };
        $this->checkFinished();
        $images = $this->images();
        [$running] = $this->running();
        $image = $images->supports($running) ? $images->image($running) : $images->image($images->default);
        $volume = $this->containers->volume();
        $others = array_values(array_filter($this->containers->majorsOnVolume($image, $volume), static fn (int $major): bool => $major !== $running));

        if ($dryRun || [] === $others) {
            return $others;
        }

        try {
            foreach ($others as $major) {
                $log(\sprintf('Removing PostgreSQL %d\'s data', $major));
                $this->containers->removeMajor($image, $volume, $major);
            }
        } catch (\Throwable $e) {
            $this->record('server.database-clean', [], $channel, $requestedBy, $lines, $e);

            throw $e;
        }

        $this->record('server.database-clean', [], $channel, $requestedBy, $lines, null);

        return $others;
    }

    /**
     * @throws \DomainException When an upgrade was cut short (to put back first)
     */
    private function checkFinished(): void
    {
        $latest = $this->latest();

        if ($latest instanceof DatabaseUpgrade && $latest->unfinished()) {
            throw new \DomainException(\sprintf('An upgrade of PostgreSQL was cut short (%s): put the server back first ("quire-server database:upgrade --recover").', $latest->createdAt->format('Y-m-d H:i')));
        }
    }

    /**
     * @throws \RuntimeException When anything but this updater is still connected to the old major
     */
    private function checkQuiet(): void
    {
        // This updater's own connection, which holds the server's lock
        $pid = $this->connection->fetchOne('SELECT pg_backend_pid()');
        $others = trim($this->query('postgres', \sprintf(
            "SELECT string_agg(DISTINCT usename || ' on ' || coalesce(datname, '-'), ', ') FROM pg_stat_activity WHERE backend_type = 'client backend' AND pid <> pg_backend_pid() AND pid <> %d",
            is_numeric($pid) ? (int) $pid : 0,
        )));

        if ('' !== $others) {
            throw new \RuntimeException(\sprintf('Something still uses the server\'s PostgreSQL (%s): what it writes now would be lost.', $others));
        }
    }

    /**
     * @param list<string> $databases
     *
     * @return array{tables: int, rows: int}
     *
     * @throws \RuntimeException When anything differs
     */
    private function check(array $databases): array
    {
        $differences = [];
        $counts = ['tables' => 0, 'rows' => 0];

        foreach (DatabaseCheck::differences($this->containers->query(true, 'postgres', DatabaseCheck::CLUSTER), $this->containers->query(false, 'postgres', DatabaseCheck::CLUSTER)) as $difference) {
            $differences[] = $difference;
        }

        foreach ($databases as $database) {
            $old = $this->containers->query(true, $database, DatabaseCheck::DATABASE);

            foreach (DatabaseCheck::differences($old, $this->containers->query(false, $database, DatabaseCheck::DATABASE)) as $difference) {
                $differences[] = $database.': '.$difference;
            }

            $count = DatabaseCheck::counts($old);
            $counts['tables'] += $count['tables'];
            $counts['rows'] += $count['rows'];
        }

        if ([] !== $differences) {
            throw new \RuntimeException(\sprintf('The copy differs from the original: %s%s', implode('; ', \array_slice($differences, 0, 10)), \count($differences) > 10 ? \sprintf(' and %d more', \count($differences) - 10) : ''));
        }

        return $counts;
    }

    /**
     * Stops PostgreSQL, names the major in server.env and starts it alone; checks it's that major.
     */
    private function switchTo(int $major, PostgresImages $images): void
    {
        $this->stack->stopDatabase($this->folder);
        $env = $this->folder->env();
        $this->folder->writeEnv($env->with(['COMPOSE_FILE' => $images->composeFile($env->get('COMPOSE_FILE') ?? 'compose.yaml', $major)]));
        $this->stack->up($this->folder, max($this->wait, self::ROLLBACK_WAIT), services: ['database']);
        $this->connection->close();
        [$running] = $this->running();

        if ($running !== $major) {
            throw new \RuntimeException(\sprintf('PostgreSQL %d runs, not %d.', $running, $major));
        }
    }

    /**
     * The sites stopped for it, then the rest of the server (the agent).
     *
     * @param \Closure(string): void $log
     */
    private function restart(DatabaseUpgrade $upgrade, \Closure $log): void
    {
        $log('Starting the sites again');
        $this->containers->start($upgrade->stopped);
        $log('Starting the agent');
        $this->stack->up($this->folder, max($this->wait, self::ROLLBACK_WAIT));
    }

    /**
     * Back to the upgrade's old major (its "from"), with everything it stopped started again; the new
     * major's data removed, unless it holds what was written after an upgrade (an undo's).
     *
     * @param \Closure(string): void $log
     */
    private function rollBack(DatabaseUpgrade $upgrade, PostgresImages $images, string $state, \Closure $log, ?\Throwable $cause, ?DatabaseUpgrade $record = null): DatabaseUpgradeFailed
    {
        $record ??= $upgrade;
        $log(\sprintf('Putting the server back on PostgreSQL %d', $upgrade->from));

        try {
            $this->containers->removeBeside();

            // Before the switch, the old major still runs, untouched: no need to restart it.
            if ($this->runningMajor() !== $upgrade->from || $this->folder->env()->get('COMPOSE_FILE') !== $images->composeFile($this->folder->env()->get('COMPOSE_FILE') ?? 'compose.yaml', $upgrade->from)) {
                $this->switchTo($upgrade->from, $images);
            }

            // An unfinished copy goes; a newer major's data that sites wrote to (an undo's) stays.
            if ($upgrade->to > $upgrade->from && DatabaseUpgrade::UNDOING !== $record->state) {
                $image = $images->image($upgrade->from);
                $volume = $this->containers->volume();

                if (\in_array($upgrade->to, $this->containers->majorsOnVolume($image, $volume), true)) {
                    $log(\sprintf('Removing the unfinished copy (PostgreSQL %d)', $upgrade->to));
                    $this->containers->removeMajor($image, $volume, $upgrade->to);
                }
            }

            $this->restart($record, $log);
        } catch (\Throwable $e) {
            $log('Putting it back failed: '.$e->getMessage());

            return new DatabaseUpgradeFailed(\sprintf('%sputting the server back on PostgreSQL %d failed: %s', $cause instanceof \Throwable ? $cause->getMessage().'; ' : '', $upgrade->from, $e->getMessage()), restored: false, previous: $cause ?? $e);
        }

        $record->with($state);
        $log(\sprintf('It runs as it was, on PostgreSQL %d', $upgrade->from));

        return new DatabaseUpgradeFailed($cause instanceof \Throwable ? $cause->getMessage().' (the server runs as it was)' : 'Put back.', restored: true, previous: $cause);
    }

    /**
     * The major that runs, or null when PostgreSQL doesn't answer.
     */
    private function runningMajor(): ?int
    {
        try {
            return $this->running()[0];
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function query(string $database, string $sql): string
    {
        return $this->stack->query($this->folder, $database, $sql);
    }

    private function unlock(): void
    {
        try {
            $this->lock->unlockServer();
        } catch (\Throwable) {
            // Its connection ended (PostgreSQL was restarted): so did the lock.
        }
    }

    private function upgrades(): string
    {
        return DatabaseUpgrade::directory($this->root);
    }

    private function upgradeDirectory(): string
    {
        // In the order they're made (the newest is the one that counts)
        return \sprintf('%s/%s', $this->upgrades(), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Ymd-His-u'));
    }

    private function bytes(int $bytes): string
    {
        foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'kB' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return \sprintf('%.1f %s', $bytes / $size, $unit);
            }
        }

        return $bytes.' bytes';
    }

    /**
     * The operation, recorded once it's over (PostgreSQL is restarted while it runs).
     *
     * @param array<string, string> $parameters
     * @param list<string>          $lines
     */
    private function record(string $action, array $parameters, string $channel, string $requestedBy, array $lines, ?\Throwable $error): void
    {
        $this->retrying(function () use ($action, $parameters, $channel, $requestedBy, $lines, $error): true {
            $operation = $this->operations->start($action, null, $parameters, $channel, $requestedBy);

            foreach ($lines as $line) {
                $operation->line($line);
            }

            $error instanceof \Throwable ? $operation->fail($error->getMessage()) : $operation->succeed();

            return true;
        });
    }

    /**
     * @template T
     *
     * @param \Closure(): T $query
     *
     * @return T|null
     */
    private function retrying(\Closure $query, int $attempts = 30): mixed
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                return $query();
            } catch (DBALException) {
                if ($attempt >= $attempts) {
                    return null;
                }

                $this->connection->close();
                ($this->pause)(2);
            }
        }
    }
}
