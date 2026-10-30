<?php

declare(strict_types=1);

namespace Quire\Server\ServerUpdate;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Release\Release;
use Quire\Server\Release\Releases;
use Quire\Server\Release\ReleaseVerificationFailed;
use Quire\Server\Release\Version;
use Quire\Server\Site\EnvFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Updates the server itself to a newer release, and undoes that. It runs in the updater, a container
 * of the release that runs now (never of the one it updates to: that one is checked before any of it
 * is used), with Docker and the server's folder:
 *
 * get the release and check its signature → check its Compose files with this server's settings → get
 * its image → wait for operations on sites to finish, and stop the agent → back up the server's files,
 * settings and database → replace the files → start it → check every part is healthy and the agent is
 * the new release. If anything fails after the files changed, the backup is put back (the database
 * too, when the new release's migrations ran) and the server runs as it was.
 *
 * Sites keep running throughout: they're Compose projects of their own. They're offline only for the
 * seconds the front door (or PostgreSQL) is replaced, when the release changes them.
 */
final readonly class ServerUpdater
{
    private const int ROLLBACK_WAIT = 300;

    public function __construct(
        private Releases $releases,
        private ServerFolder $folder,
        private ServerStack $stack,
        private SiteLock $lock,
        private Connection $connection,
        private OperationLog $operations,
        private string $root,
        private string $running,
        private int $wait = 300,
        private string $migrationsTable = 'doctrine_migration_versions',
        private \Closure $pause = sleep(...),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * The release that runs, or null when it was built from a checkout.
     */
    public function running(): ?Version
    {
        return Version::tryFrom($this->running);
    }

    /**
     * The release an update would move to: the one asked for, or the latest; null when it runs that.
     *
     * @throws \DomainException          When there's none, or it's older than the one that runs
     * @throws ReleaseVerificationFailed When GitHub can't be reached
     */
    public function target(?Version $asked): ?Version
    {
        $target = $asked ?? $this->releases->latest() ?? throw new \DomainException('There is no release of Quire Server yet.');
        $running = $this->running();

        if (!$running instanceof Version) {
            return $target;
        }

        if ($target->compare($running) < 0) {
            throw new \DomainException(\sprintf('Quire Server %s is older than the one that runs (%s): "update --undo" goes back after an update.', $target, $running));
        }

        return $target->isNewerThan($running) ? $target : null;
    }

    /**
     * @param \Closure(string): void $say
     *
     * @throws ServerUpdateFailed
     */
    public function update(Version $target, string $channel, string $requestedBy, \Closure $say): void
    {
        $lines = [];
        $log = static function (string $line) use (&$lines, $say): void {
            $lines[] = $line;
            $say($line);
        };
        $work = sys_get_temp_dir().'/quire-server-release-'.bin2hex(random_bytes(6));

        try {
            $log(\sprintf('Getting Quire Server %s and checking it', $target));
            $release = $this->releases->fetch($target, $work, $log);
            $env = $this->folder->env()->with(['QUIRE_SERVER_IMAGE' => $release->image, 'COMPOSE_FILE' => 'compose.yaml']);
            $staged = $work.'/server.env';
            ServerFolder::writeSecret($staged, $env->content());
            $this->stack->validate($release->files, $staged);
            $log('Its Compose files check out with this server\'s settings');
            $log('Getting its image');
            $this->stack->pull($release->image);

            $this->swap(
                new ServerBackup($this->backupDirectory(), $this->running, $target->value, [], new \DateTimeImmutable()),
                function () use ($release, $env): void { $this->install($release, $env); },
                $target->value,
                $log,
            );
        } catch (\Throwable $e) {
            $this->record('server.update', ['version' => $target->value], $channel, $requestedBy, $lines, $e);

            throw $e instanceof ServerUpdateFailed ? $e : new ServerUpdateFailed($e->getMessage(), restored: true, previous: $e);
        } finally {
            $this->filesystem->remove($work);
        }

        $this->record('server.update', ['version' => $target->value], $channel, $requestedBy, $lines, null);
    }

    /**
     * The last update's backup, while it can be undone (a week).
     */
    public function undoable(): ?ServerBackup
    {
        return ServerBackup::undoable($this->root.'/backups/server', $this->running, new \DateTimeImmutable());
    }

    /**
     * Whether undoing it puts back the database too (the update's migrations ran): what changed in the
     * registry since (sites added, operations) would be lost.
     */
    public function undoRestoresDatabase(ServerBackup $backup): bool
    {
        return $this->migrations() !== $backup->migrations;
    }

    /**
     * Puts back the server as it was before its last update.
     *
     * @param \Closure(string): void $say
     *
     * @throws ServerUpdateFailed
     */
    public function undo(ServerBackup $backup, string $channel, string $requestedBy, \Closure $say): void
    {
        $lines = [];
        $log = static function (string $line) use (&$lines, $say): void {
            $lines[] = $line;
            $say($line);
        };

        try {
            $log(\sprintf('Undoing the update from %s to %s (%s)', $backup->from, $backup->to, $backup->createdAt->format('Y-m-d H:i')));
            $safety = $this->swap(
                new ServerBackup($this->backupDirectory(), $this->running, $backup->from, [], new \DateTimeImmutable()),
                function () use ($backup, $log): void { $this->putBack($backup, $log); },
                $backup->from,
                $log,
            );
            $backup->undone(new \DateTimeImmutable())->save();
            // Only to roll the undo back: an undo isn't undone (updating again is the same).
            $this->filesystem->remove($safety->directory);
        } catch (\Throwable $e) {
            $this->record('server.undo', ['version' => $backup->from], $channel, $requestedBy, $lines, $e);

            throw $e instanceof ServerUpdateFailed ? $e : new ServerUpdateFailed($e->getMessage(), restored: true, previous: $e);
        }

        $this->record('server.undo', ['version' => $backup->from], $channel, $requestedBy, $lines, null);
    }

    /**
     * Stops the agent (once no operation on a site runs), backs the server up, changes it, starts it
     * and checks it runs the release it should; rolls it back to the backup if that fails.
     *
     * @param \Closure(): void       $change
     * @param \Closure(string): void $log
     *
     * @return ServerBackup Its backup
     */
    private function swap(ServerBackup $backup, \Closure $change, string $expected, \Closure $log): ServerBackup
    {
        if (!$this->lock->lockServer()) {
            throw new \DomainException('An operation on a site runs: try again when it\'s finished ("quire-server operations").');
        }

        $log('Stopping the agent (sites keep running)');
        // Until it's over, either way: what the machine's next start (and "quire-server") finds when
        // it stopped in the middle.
        $this->filesystem->mkdir(\dirname($this->swapRecord()), 0o700);
        ServerFolder::writeSecret($this->swapRecord(), json_encode(['from' => $backup->from, 'to' => $expected, 'started_at' => new \DateTimeImmutable()->format(\DATE_ATOM)], \JSON_THROW_ON_ERROR)."\n");

        try {
            $this->stack->stopAgent($this->folder);
            $log('Backing up the server: its files, settings and database');
            $backup = $this->backUp($backup);
            $log('Backed up to '.$backup->directory);
        } catch (\Throwable $e) {
            $this->restart($log);
            $this->filesystem->remove($this->swapRecord());

            throw $e;
        }

        try {
            $change();
            $log('Starting it (each part checked healthy)');
            $this->stack->up($this->folder, $this->wait);
            $version = $this->stack->version($this->folder);

            if (\sprintf('Quire Server %s', $expected) !== strtok($version, "\n")) {
                throw new \RuntimeException(\sprintf('The agent says it\'s "%s", not Quire Server %s.', strtok($version, "\n"), $expected));
            }

            $log(\sprintf('Quire Server %s runs', $expected));

            return $backup;
        } catch (\Throwable $e) {
            $log('It failed: '.$e->getMessage());
            $log('Putting the server back as it was');

            try {
                $this->putBack($backup, $log);
                // What ran before gets all the time it needs, however short the update's wait is.
                $this->stack->up($this->folder, max($this->wait, self::ROLLBACK_WAIT));
            } catch (\Throwable $rollback) {
                throw new ServerUpdateFailed(\sprintf('%s; putting it back failed too: %s', $e->getMessage(), $rollback->getMessage()), restored: false, backup: $backup->directory, previous: $e);
            }

            $log(\sprintf('It runs as it was (%s)', $backup->from));
            // It runs as the backup has it: the backup would only hide the update before, to undo.
            $this->filesystem->remove($backup->directory);

            throw new ServerUpdateFailed($e->getMessage().' (the server runs as it was)', restored: true, backup: $backup->directory, previous: $e);
        } finally {
            $this->filesystem->remove($this->swapRecord());
            $this->unlock();
        }
    }

    private function install(Release $release, EnvFile $env): void
    {
        $this->folder->replace($release->files);
        $this->folder->writeEnv($env);
    }

    /**
     * The backup's files and settings, and its database when the registry's migrations differ from
     * its (or can't be read).
     *
     * @param \Closure(string): void $log
     */
    private function putBack(ServerBackup $backup, \Closure $log): void
    {
        $this->folder->restore($backup->files());

        if ($this->migrations() !== $backup->migrations) {
            $log('Its database changed (migrations ran): restoring it from the backup');
            $this->stack->stopAgent($this->folder);
            $this->stack->restoreDatabase($this->folder, $backup->database());
        }
    }

    private function backUp(ServerBackup $backup): ServerBackup
    {
        $this->filesystem->mkdir($backup->directory, 0o700);
        $backup = new ServerBackup($backup->directory, $backup->from, $backup->to, $this->migrations() ?? throw new \RuntimeException('The server\'s database can\'t be read.'), $backup->createdAt);
        $this->folder->archive($backup->files());
        $this->stack->dumpDatabase($this->folder, $backup->database());
        // Last: a backup without it is incomplete, and never undone to.
        $backup->save();

        return $backup;
    }

    /**
     * @param \Closure(string): void $log
     */
    private function restart(\Closure $log): void
    {
        try {
            $this->stack->up($this->folder, max($this->wait, self::ROLLBACK_WAIT));
        } catch (\Throwable $e) {
            $log('Starting the agent again failed: '.$e->getMessage());
        } finally {
            $this->unlock();
        }
    }

    private function unlock(): void
    {
        try {
            $this->lock->unlockServer();
        } catch (\Throwable) {
            // Its connection ended (PostgreSQL was replaced): so did the lock.
        }
    }

    /**
     * The registry's migrations that ran, or null when they can't be read.
     *
     * @return list<string>|null
     */
    private function migrations(): ?array
    {
        return $this->retrying(fn (): array => array_map(static fn (mixed $version): string => \is_scalar($version) ? (string) $version : '', $this->connection->fetchFirstColumn(\sprintf('SELECT version FROM %s ORDER BY version', $this->migrationsTable))), 15);
    }

    /**
     * An update (or undo) of the server that was cut short (the machine stopped in the middle): from
     * which release to which; null when none was.
     *
     * @return array{from: string, to: string}|null
     */
    public function cutShort(): ?array
    {
        $record = @file_get_contents($this->swapRecord());
        $swap = false === $record ? null : json_decode($record, true);

        return \is_array($swap) && \is_string($swap['from'] ?? null) && \is_string($swap['to'] ?? null) ? ['from' => $swap['from'], 'to' => $swap['to']] : null;
    }

    private function swapRecord(): string
    {
        return $this->root.'/backups/server/swap.json';
    }

    private function backupDirectory(): string
    {
        // In the order they're made (the newest is the one to undo)
        return \sprintf('%s/backups/server/%s', $this->root, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Ymd-His-u'));
    }

    /**
     * The operation, recorded once it's over (the database may be replaced while it runs).
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
        }, 30);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $query
     *
     * @return T|null
     */
    private function retrying(\Closure $query, int $attempts): mixed
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
