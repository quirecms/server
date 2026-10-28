<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerUpdate;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\SymfonyProcessRunner;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Release\Releases;
use Quire\Server\Release\Version;
use Quire\Server\ServerUpdate\ServerBackup;
use Quire\Server\ServerUpdate\ServerFolder;
use Quire\Server\ServerUpdate\ServerStack;
use Quire\Server\ServerUpdate\ServerUpdateFailed;
use Quire\Server\ServerUpdate\ServerUpdater;
use Quire\Server\Site\EnvFile;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Updating the server itself and undoing it, with GitHub, cosign and Docker scripted as they would
 * behave (which release each image is, whether it starts, the migrations its agent runs), and the real
 * files, registry and locks: above all, what the server is left with when something fails.
 */
final class ServerUpdaterTest extends RegistryTestCase
{
    private const string OLD = 'ghcr.io/quirecms/server@sha256:1111111111111111111111111111111111111111111111111111111111111111';
    private const string NEW = 'ghcr.io/quirecms/server@sha256:4444444444444444444444444444444444444444444444444444444444444444';
    private const string SECRET = 'QUIRE_SERVER_POSTGRES_PASSWORD=s3cret';

    private string $root;
    private string $server;
    private ScriptedRunner $runner;
    private OperationLog $operations;
    private string $migrations;

    /** @var array<string, string> The release each image is */
    private array $releases = [self::OLD => '0.1.1', self::NEW => '0.2.0', 'quire-server-agent' => 'dev'];

    /** The image the agent runs, or null when it's stopped */
    private ?string $agent = self::OLD;

    /** @var list<string> Images whose agent doesn't start (unhealthy) */
    private array $failing = [];

    /** @var array<string, list<string>> Migrations each image's agent runs as it starts */
    private array $migratesTo = [];
    private bool $verified = true;
    private bool $composeValid = true;
    private bool $restoreFails = false;

    /** @var list<string> */
    private array $said = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-server-update-'.bin2hex(random_bytes(6));
        $this->server = $this->root.'/server';
        $this->operations = new OperationLog($this->connection, $this->schema);
        $this->migrations = $this->connection->quoteSingleIdentifier($this->schema).'.migration_versions';
        $this->connection->executeStatement(\sprintf('CREATE TABLE %s (version text PRIMARY KEY)', $this->migrations));
        $this->connection->executeStatement(\sprintf("INSERT INTO %s VALUES ('Version1'), ('Version2')", $this->migrations));

        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->server.'/compose.yaml', 'release: 0.1.1');
        $filesystem->dumpFile($this->server.'/bin/quire-server', 'command: 0.1.1');
        $filesystem->dumpFile($this->server.'/only-in-0.1.1.txt', 'gone after the update');
        $filesystem->dumpFile($this->server.'/server.env', "# Mine\n".self::SECRET."\nCOMPOSE_FILE=compose.yaml\nQUIRE_SERVER_IMAGE=".self::OLD."\n");
        chmod($this->server.'/server.env', 0o600);
        $filesystem->dumpFile($this->server.'/domains/jane.example', 'jane');
        $filesystem->dumpFile($this->server.'/keys/blog', 'a deploy key');
        $filesystem->mkdir($this->root.'/backups', 0o700);

        $archive = $this->archive([
            'quire-server-0.2.0/compose.yaml' => 'release: 0.2.0',
            'quire-server-0.2.0/bin/quire-server' => 'command: 0.2.0',
            'quire-server-0.2.0/only-in-0.2.0.txt' => 'new',
            // A release never brings the machine's own files
            'quire-server-0.2.0/server.env' => 'QUIRE_SERVER_POSTGRES_PASSWORD=from-the-release',
            'quire-server-0.2.0/keys/blog' => 'from the release',
        ]);
        $releaseEnv = \sprintf("VERSION=0.2.0\nIMAGE=%s\nARCHIVE=quire-server-0.2.0.tar.gz\nARCHIVE_SHA256=%s\n", self::NEW, hash('sha256', $archive));
        $served = ['release.env' => $releaseEnv, 'release.env.sigstore.json' => '{}', 'quire-server-0.2.0.tar.gz' => $archive];
        $real = new SymfonyProcessRunner();
        $delegate = static fn (array $command): ProcessResult => $real->run(array_values(array_map(static fn (mixed $part): string => \is_string($part) ? $part : '', $command)));

        $this->runner = new ScriptedRunner([
            'curl -fsSL' => static function (array $command) use ($served): ProcessResult {
                if (\in_array('%{url_effective}', $command, true)) {
                    return ScriptedRunner::ok($command, 'https://github.com/quirecms/server/releases/tag/v0.2.0');
                }

                file_put_contents(self::after($command, '-o'), $served[basename((string) end($command))]);

                return ScriptedRunner::ok($command);
            },
            'cosign verify-blob' => fn (array $command): ProcessResult => $this->verified ? ScriptedRunner::ok($command) : ScriptedRunner::failed($command, 'Error: none of the expected identities matched'),
            'tar -tzf' => $delegate,
            'tar -xzf' => $delegate,
            'tar -cf' => $delegate,
            'tar -xf' => $delegate,
            'cp -R' => $delegate,
            'chmod -R' => $delegate,
            'docker compose config' => fn (array $command): ProcessResult => $this->composeValid ? ScriptedRunner::ok($command) : ScriptedRunner::failed($command, 'required variable QUIRE_SERVER_NEW_SECRET is missing a value'),
            'docker pull --quiet' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, (string) end($command)),
            'docker compose stop' => function (array $command): ProcessResult {
                $this->agent = null;

                return ScriptedRunner::ok($command);
            },
            'docker compose up' => function (array $command, ?string $directory): ProcessResult {
                $image = $this->image();
                $this->agent = $image;

                foreach ($this->migratesTo[$image] ?? [] as $version) {
                    $this->connection->executeStatement(\sprintf('INSERT INTO %s VALUES (?) ON CONFLICT DO NOTHING', $this->migrations), [$version]);
                }

                return \in_array($image, $this->failing, true) ? ScriptedRunner::failed($command, "container quire-server-agent-1 is unhealthy\n") : ScriptedRunner::ok($command);
            },
            'docker compose exec' => fn (array $command, ?string $directory, ?string $inputFile): ProcessResult => match (true) {
                \in_array('server:version', $command, true) => null === $this->agent ? ScriptedRunner::failed($command, 'service "agent" is not running') : ScriptedRunner::ok($command, \sprintf("Quire Server %s\nAgent: %s\n", $this->releases[$this->agent], $this->agent)),
                \in_array('pg_dump', $command, true) => ScriptedRunner::ok($command, 'dump:'.implode(',', $this->migrationVersions())),
                \in_array('pg_restore', $command, true) => $this->restore($command, (string) $inputFile),
                default => throw new \LogicException('Unexpected: '.implode(' ', $command)),
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testUpdatingTheServer(): void
    {
        // The updater's own settings (it runs the release before), which Compose would take over server.env's
        $_ENV['QUIRE_SERVER_IMAGE'] = self::OLD;

        try {
            $this->update();
        } finally {
            unset($_ENV['QUIRE_SERVER_IMAGE']);
        }

        foreach ($this->runner->runs as $run) {
            if ('compose' === ($run['command'][1] ?? null)) {
                self::assertFalse($run['env']['QUIRE_SERVER_IMAGE'] ?? null, 'Only server.env names the image.');
            }
        }

        self::assertSame(self::NEW, $this->agent);
        self::assertSame('release: 0.2.0', file_get_contents($this->server.'/compose.yaml'));
        self::assertSame('command: 0.2.0', file_get_contents($this->server.'/bin/quire-server'));
        self::assertFileDoesNotExist($this->server.'/only-in-0.1.1.txt', 'The old release\'s files are gone.');
        self::assertFileExists($this->server.'/only-in-0.2.0.txt');

        // The machine's own files stay as they were; the release's copies of them are never used.
        self::assertSame('jane', file_get_contents($this->server.'/domains/jane.example'));
        self::assertSame('a deploy key', file_get_contents($this->server.'/keys/blog'));
        $env = (string) file_get_contents($this->server.'/server.env');
        self::assertStringContainsString("# Mine\n".self::SECRET."\n", $env);
        self::assertSame(self::NEW, $this->image());
        self::assertSame(0o600, fileperms($this->server.'/server.env') & 0o777);

        // Checked and its image got while the server runs; stopped only then, backed up, started.
        self::assertSame(['curl -fsSL', 'curl -fsSL', 'cosign verify-blob', 'curl -fsSL', 'tar -tzf', 'tar -xzf', 'docker compose config', 'docker pull --quiet', 'docker compose stop', 'tar -cf', 'docker compose exec', 'cp -R', 'chmod -R', 'docker compose up', 'docker compose exec'], $this->runner->keys());

        // Its secrets never in a command's arguments
        foreach ($this->runner->runs as $run) {
            self::assertStringNotContainsString('s3cret', implode(' ', $run['command']));
        }

        $backup = $this->updater('0.2.0')->undoable();
        self::assertInstanceOf(ServerBackup::class, $backup);
        self::assertSame(['0.1.1', '0.2.0', ['Version1', 'Version2']], [$backup->from, $backup->to, $backup->migrations]);
        self::assertSame(0o700, fileperms($backup->directory) & 0o777);
        self::assertSame(0o600, fileperms($backup->files()) & 0o777);
        self::assertSame(0o600, fileperms($backup->database()) & 0o777);

        $operation = $this->operations->latest()[0];
        self::assertSame(['server.update', null, 'succeeded', 'darko'], [$operation['action'], $operation['site'], $operation['status'], $operation['requested_by']]);
        self::assertSame($this->said, $this->operations->lines($operation['id']));
        self::assertContains('Quire Server 0.2.0 runs', $this->said);
    }

    public function testAReleaseThatDoesNotCheckOutChangesNothing(): void
    {
        $this->verified = false;
        $this->failsAndRuns('/didn\'t check out/', self::OLD);
        self::assertNotContains('docker pull --quiet', $this->runner->keys());
        self::assertNotContains('docker compose stop', $this->runner->keys(), 'The agent never stopped.');
        self::assertSame('failed', $this->operations->latest()[0]['status']);
    }

    public function testComposeFilesThatDoNotWorkWithTheServersSettingsChangeNothing(): void
    {
        $this->composeValid = false;
        $this->failsAndRuns('/QUIRE_SERVER_NEW_SECRET/', self::OLD);
        self::assertNotContains('docker compose stop', $this->runner->keys());
    }

    public function testItWaitsForOperationsOnSites(): void
    {
        $other = $this->otherConnection();
        // An operation on a site runs (it holds the server's lock, shared).
        $other->fetchOne('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [SiteLock::SERVER]);

        try {
            $this->failsAndRuns('/An operation on a site runs/', self::OLD);
            self::assertNotContains('docker compose stop', $this->runner->keys());
        } finally {
            $other->close();
        }
    }

    public function testNoOperationOnASiteStartsWhileItUpdates(): void
    {
        $other = $this->otherConnection();

        try {
            self::assertTrue(new SiteLock($this->connection)->lockServer());
            $this->expectExceptionMessageIsOrContains('The server is being updated');
            new SiteLock($other)->run(new SiteName('jane'), static fn (): bool => true);
        } finally {
            $other->close();
        }
    }

    public function testANewReleaseThatDoesNotStartIsRolledBack(): void
    {
        $this->failing = [self::NEW];
        $error = $this->failsAndRuns('/unhealthy.*\(the server runs as it was\)/s', self::OLD);

        self::assertTrue($error->restored);
        self::assertSame('release: 0.1.1', file_get_contents($this->server.'/compose.yaml'));
        self::assertFileExists($this->server.'/only-in-0.1.1.txt');
        self::assertFileDoesNotExist($this->server.'/only-in-0.2.0.txt');
        self::assertSame(self::OLD, $this->image());
        self::assertSame(0o600, fileperms($this->server.'/server.env') & 0o777);
        self::assertNotContains('pg_restore', array_merge(...array_map(static fn (array $run): array => $run['command'], $this->runner->runs)), 'Its migrations didn\'t run: the database stays.');
        self::assertSame('failed', $this->operations->latest()[0]['status']);
        self::assertContains('It runs as it was (0.1.1)', $this->operations->lines($this->operations->latest()[0]['id']));
        self::assertNull($this->updater('0.1.1')->undoable(), 'Nothing to undo.');
        self::assertSame([], glob($this->root.'/backups/server/*'), 'Its backup was the server as it runs: not kept.');
    }

    public function testItsDatabaseIsRestoredWhenTheNewReleasesMigrationsRan(): void
    {
        $this->failing = [self::NEW];
        $this->migratesTo = [self::NEW => ['Version3']];
        $this->failsAndRuns('/the server runs as it was/', self::OLD);

        self::assertSame(['Version1', 'Version2'], $this->migrationVersions());
        self::assertContains('Its database changed (migrations ran): restoring it from the backup', $this->said);
    }

    public function testAnAgentThatIsNotTheNewReleaseIsRolledBack(): void
    {
        // It started, but runs the old release (e.g. the image wasn't the one named)
        $this->releases[self::NEW] = '0.1.1';
        $this->failsAndRuns('/says it\'s "Quire Server 0\.1\.1", not Quire Server 0\.2\.0/', self::OLD);
    }

    public function testWhenPuttingItBackFailsTooItSaysWhereItsBackupIs(): void
    {
        $this->failing = [self::NEW];
        $this->migratesTo = [self::NEW => ['Version3']];
        $this->restoreFails = true;

        try {
            $this->update();
            self::fail('It updated.');
        } catch (ServerUpdateFailed $e) {
            self::assertFalse($e->restored);
            self::assertNotNull($e->backup);
            self::assertDirectoryExists($e->backup);
            self::assertStringContainsString('putting it back failed too', $e->getMessage());
        }
    }

    public function testUndoingTheUpdate(): void
    {
        $this->migratesTo = [self::NEW => ['Version3']];
        $this->update();
        $updated = $this->updater('0.2.0');
        $backup = $updated->undoable();
        self::assertInstanceOf(ServerBackup::class, $backup);
        self::assertTrue($updated->undoRestoresDatabase($backup), 'Its migrations ran: undoing it restores the database.');

        $updated->undo($backup, 'cli', 'darko', static function (): void {});

        self::assertSame(self::OLD, $this->agent);
        self::assertSame('release: 0.1.1', file_get_contents($this->server.'/compose.yaml'));
        self::assertFileDoesNotExist($this->server.'/only-in-0.2.0.txt');
        self::assertSame(self::OLD, $this->image());
        self::assertSame(['Version1', 'Version2'], $this->migrationVersions());
        self::assertSame('a deploy key', file_get_contents($this->server.'/keys/blog'));
        self::assertSame(['server.undo', 'succeeded'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status']]);

        // Once: it's undone, and the release that runs now was never updated to.
        self::assertNull($this->updater('0.1.1')->undoable());
        self::assertNull($updated->undoable());
    }

    public function testAnUndoThatFailsIsRolledBackToTheUpdate(): void
    {
        $this->update();
        $updated = $this->updater('0.2.0');
        $backup = $updated->undoable();
        self::assertInstanceOf(ServerBackup::class, $backup);
        $this->failing = [self::OLD];

        try {
            $updated->undo($backup, 'cli', 'darko', static function (): void {});
            self::fail('It was undone.');
        } catch (ServerUpdateFailed $e) {
            self::assertTrue($e->restored);
        }

        self::assertSame(self::NEW, $this->agent);
        self::assertSame('release: 0.2.0', file_get_contents($this->server.'/compose.yaml'));
        self::assertInstanceOf(ServerBackup::class, $updated->undoable(), 'It can still be undone.');
    }

    public function testOnlyTheLastUpdateCanBeUndoneForAWeek(): void
    {
        $this->update();
        $backup = $this->updater('0.2.0')->undoable();
        self::assertInstanceOf(ServerBackup::class, $backup);

        new ServerBackup($backup->directory, $backup->from, $backup->to, $backup->migrations, new \DateTimeImmutable('-8 days'))->save();
        $tooOld = $this->updater('0.2.0');
        self::assertNull($tooOld->undoable());

        // A newer backup that isn't complete (no database dump) is skipped, not undone to.
        new ServerBackup($backup->directory, $backup->from, $backup->to, $backup->migrations, new \DateTimeImmutable())->save();
        new Filesystem()->dumpFile($this->root.'/backups/server/99999999-999999-ffff/backup.json', '{"from": "0.0.1", "to": "0.2.0", "migrations": [], "created_at": "2099-01-01T00:00:00+00:00"}');
        $updater = $this->updater('0.2.0');
        $undoable = $updater->undoable();
        self::assertInstanceOf(ServerBackup::class, $undoable);
        self::assertSame($backup->directory, $undoable->directory);
    }

    public function testWhichReleaseItUpdatesTo(): void
    {
        self::assertSame('0.2.0', $this->updater('0.1.1')->target(null)?->value, 'The latest');
        self::assertNull($this->updater('0.2.0')->target(null), 'It runs the latest');
        self::assertSame('0.2.0', $this->updater('dev')->target(null)?->value, 'A build from a checkout moves to a release');
        self::assertSame('0.3.0-rc.1', $this->updater('0.2.0')->target(new Version('0.3.0-rc.1'))?->value);

        $this->expectExceptionMessageIsOrContains('older than the one that runs');
        $this->updater('0.2.0')->target(new Version('0.1.1'));
    }

    private function update(): void
    {
        $this->updater('0.1.1')->update(new Version('0.2.0'), 'cli', 'darko', function (string $line): void {
            $this->said[] = $line;
        });
    }

    private function failsAndRuns(string $message, string $image): ServerUpdateFailed
    {
        try {
            $this->update();
            self::fail('It updated.');
        } catch (ServerUpdateFailed $e) {
            self::assertMatchesRegularExpression($message, $e->getMessage());
            self::assertSame($image, $this->agent, 'The agent runs as before.');

            return $e;
        }
    }

    private function updater(string $running): ServerUpdater
    {
        return new ServerUpdater(
            new Releases($this->runner),
            new ServerFolder($this->runner, $this->root),
            new ServerStack($this->runner, 'unix:///var/run/docker.sock', 's3cret'),
            new SiteLock($this->connection),
            $this->connection,
            $this->operations,
            $this->root,
            $running,
            migrationsTable: $this->migrations,
            pause: static function (): void {},
        );
    }

    /**
     * The image server.env names, as Compose would start it.
     */
    private function image(): string
    {
        $image = EnvFile::read($this->server.'/server.env')->get('QUIRE_SERVER_IMAGE') ?? '';

        return '' === $image ? 'quire-server-agent' : $image;
    }

    /**
     * @param list<string> $command
     */
    private function restore(array $command, string $dump): ProcessResult
    {
        self::assertNull($this->agent, 'The agent is stopped while its database is restored.');

        if ($this->restoreFails) {
            return ScriptedRunner::failed($command, 'pg_restore: error: could not connect');
        }

        $versions = explode(',', substr((string) file_get_contents($dump), \strlen('dump:')));
        $this->connection->executeStatement(\sprintf('DELETE FROM %s', $this->migrations));

        foreach ($versions as $version) {
            $this->connection->executeStatement(\sprintf('INSERT INTO %s VALUES (?)', $this->migrations), [$version]);
        }

        return ScriptedRunner::ok($command);
    }

    /**
     * @return list<string>
     */
    private function migrationVersions(): array
    {
        return array_map(static fn (mixed $version): string => \is_string($version) ? $version : '', $this->connection->fetchFirstColumn(\sprintf('SELECT version FROM %s ORDER BY version', $this->migrations)));
    }

    private function otherConnection(): Connection
    {
        /** @var array{driver: 'pdo_pgsql'} $params The same database, through a connection of its own */
        $params = $this->connection->getParams();

        return DriverManager::getConnection($params);
    }

    /**
     * @param array<string, string> $files
     */
    private function archive(array $files): string
    {
        $source = $this->root.'/archive';

        foreach ($files as $path => $content) {
            new Filesystem()->dumpFile($source.'/'.$path, $content);
        }

        new SymfonyProcessRunner()->run(['tar', '-czf', $this->root.'/archive.tar.gz', '-C', $source, 'quire-server-0.2.0'])->orFail('Making the archive');
        $archive = (string) file_get_contents($this->root.'/archive.tar.gz');
        new Filesystem()->remove([$source, $this->root.'/archive.tar.gz']);

        return $archive;
    }

    /**
     * The value after an option in a command, e.g. the file after "-o".
     *
     * @param list<string> $command
     */
    private static function after(array $command, string $option): string
    {
        $at = array_search($option, $command, true);
        self::assertIsInt($at, $option.' is given');

        return $command[$at + 1] ?? '';
    }
}
