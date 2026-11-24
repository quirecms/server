<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerUpdate;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Quire\Server\Console\ServerUpdateCommand;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\SymfonyProcessRunner;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\UpdatePolicies;
use Quire\Server\Release\GitHubReleases;
use Quire\Server\Release\Releases;
use Quire\Server\Release\Version;
use Quire\Server\ServerDatabase\DatabaseUpgrade;
use Quire\Server\ServerUpdate\ServerBackup;
use Quire\Server\ServerUpdate\ServerFolder;
use Quire\Server\ServerUpdate\ServerStack;
use Quire\Server\ServerUpdate\ServerUpdateFailed;
use Quire\Server\ServerUpdate\ServerUpdater;
use Quire\Server\Site\Domain;
use Quire\Server\Site\EnvFile;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Tests\ServerDatabase\PostgresImagesTest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
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
    private const string COMPOSE_011 = "# release: 0.1.1\nservices:\n  database:\n    image: ".PostgresImagesTest::PG18."\n";
    private const string COMPOSE_020 = "# release: 0.2.0\nservices:\n  database:\n    image: ".PostgresImagesTest::PG18."\n";

    private string $root;

    /** Whether the swap was written down while the new release started */
    private bool $swapRecorded = false;
    private string $server;
    private ScriptedRunner $runner;
    private OperationLog $operations;
    private string $migrations;

    /** @var array<string, string> The release each image is */
    private array $releases = [self::OLD => '0.1.1', self::NEW => '0.2.0', 'quire-server-agent' => 'dev'];

    /** What the agent says of the sites with third-party packages (null: it doesn't answer) */
    private ?string $thirdParty = '{}';

    /** The image the agent runs, or null when it's stopped */
    private ?string $agent = self::OLD;

    /** @var list<string> Images whose agent doesn't start (unhealthy) */
    private array $failing = [];

    /** @var array<string, list<string>> Migrations each image's agent runs as it starts */
    private array $migratesTo = [];
    private bool $verified = true;
    private bool $composeValid = true;
    private bool $gateAccepts = true;

    private bool $releaseHasGate = true;

    /** @var array{directory: string, env: string}|null Where the release's gate checked the sites' scopes, and its settings then */
    private ?array $gateCheck = null;
    private bool $restoreFails = false;

    /** @var list<array{compose: string, env: array<string, string>}> Each host-setup run: the compose.yaml it ran from, and the settings it was given */
    private array $hostSetups = [];
    private bool $hostSetupFails = false;
    private bool $backupSettingsUnreadable = false;

    /** The PostgreSQL major the server runs */
    private int $postgres = 18;

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
        $filesystem->dumpFile($this->server.'/compose.yaml', self::COMPOSE_011);
        $filesystem->dumpFile($this->server.'/bin/quire-server', 'command: 0.1.1');
        $filesystem->dumpFile($this->server.'/only-in-0.1.1.txt', 'gone after the update');
        $filesystem->dumpFile($this->server.'/server.env', "# Mine\n".self::SECRET."\nCOMPOSE_FILE=compose.yaml\nQUIRE_SERVER_IMAGE=".self::OLD."\n");
        $filesystem->dumpFile($this->root.'/docker.sock', '');
        chmod($this->server.'/server.env', 0o600);
        $filesystem->dumpFile($this->server.'/domains/jane.example', 'jane');
        $filesystem->dumpFile($this->server.'/keys/blog', 'a deploy key');
        $filesystem->mkdir($this->root.'/backups', 0o700);

        $archive = $this->archive([
            'quire-server-0.2.0/compose.yaml' => self::COMPOSE_020,
            'quire-server-0.2.0/bin/quire-server' => 'command: 0.2.0',
            'quire-server-0.2.0/only-in-0.2.0.txt' => 'new',
            'quire-server-0.2.0/database/postgres-19.yaml' => "services:\n  database:\n    image: ".PostgresImagesTest::PG19."\n",
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
            'tar -tvzf' => $delegate,
            'tar -xzf' => $delegate,
            'tar -cf' => $delegate,
            'tar -xf' => $delegate,
            'tar -xOf' => fn (array $command): ProcessResult => $this->backupSettingsUnreadable ? ScriptedRunner::failed($command, "tar: server.env: Not found in archive\n") : $delegate($command),
            'cp -R' => $delegate,
            'chmod -R' => $delegate,
            'docker compose config' => fn (array $command): ProcessResult => match (true) {
                '--services' === end($command) => ScriptedRunner::ok($command, "front\nagent\n".($this->releaseHasGate ? "gate\n" : 'docker-proxy'."\n")."database\n"),
                $this->composeValid => ScriptedRunner::ok($command),
                default => ScriptedRunner::failed($command, 'required variable QUIRE_SERVER_NEW_SECRET is missing a value'),
            },
            'docker compose run' => function (array $command): ProcessResult {
                $at = static fn (string $option): string => $command[(int) array_search($option, $command, true) + 1];

                if ('host-setup' === end($command)) {
                    $env = [];

                    foreach ($command as $i => $part) {
                        if ('-e' === $part) {
                            [$name, $value] = explode('=', $command[$i + 1], 2);
                            $env[$name] = $value;
                        }
                    }

                    $this->hostSetups[] = ['compose' => (string) file_get_contents($at('--project-directory').'/compose.yaml'), 'env' => $env];

                    return $this->hostSetupFails ? ScriptedRunner::failed($command, "container quire-server-host-setup-run exited (1)\n") : ScriptedRunner::ok($command);
                }

                if (['gate', 'check', '--config', '/etc/yorvogate/yorvogate.yaml'] !== \array_slice($command, -4)) {
                    throw new \LogicException('Unexpected: '.implode(' ', $command));
                }

                $this->gateCheck = ['directory' => $at('--project-directory'), 'env' => (string) file_get_contents($at('--env-file'))];

                return $this->gateAccepts
                    ? ScriptedRunner::ok($command)
                    : ScriptedRunner::failed($command, "scope /etc/yorvogate/sites/deadbeefcafe.json: prefix \"deadbeefcafe_\" starts with 12 hex digits and \"_\"\n");
            },
            'docker pull --quiet' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, (string) end($command)),
            'docker compose stop' => function (array $command): ProcessResult {
                $this->agent = null;

                return ScriptedRunner::ok($command);
            },
            'docker compose up' => function (array $command, ?string $directory): ProcessResult {
                $image = $this->image();
                $this->agent = $image;
                $this->swapRecorded = is_file($this->root.'/backups/server/swap.json');

                foreach ($this->migratesTo[$image] ?? [] as $version) {
                    $this->connection->executeStatement(\sprintf('INSERT INTO %s VALUES (?) ON CONFLICT DO NOTHING', $this->migrations), [$version]);
                }

                return \in_array($image, $this->failing, true) ? ScriptedRunner::failed($command, "container quire-server-agent-1 is unhealthy\n") : ScriptedRunner::ok($command);
            },
            'docker compose exec' => fn (array $command, ?string $directory, ?string $inputFile): ProcessResult => match (true) {
                \in_array('server:packages:third-party', $command, true) => null === $this->thirdParty ? ScriptedRunner::failed($command, 'service "agent" is not running') : ScriptedRunner::ok($command, $this->thirdParty."\n"),
                \in_array('server:version', $command, true) => null === $this->agent ? ScriptedRunner::failed($command, 'service "agent" is not running') : ScriptedRunner::ok($command, \sprintf("Quire Server %s\nAgent: %s\n", $this->releases[$this->agent], $this->agent)),
                \in_array('pg_dump', $command, true) => ScriptedRunner::ok($command, 'dump:'.implode(',', $this->migrationVersions())),
                \in_array('psql', $command, true) => ScriptedRunner::ok($command, $this->postgres."0006\n"),
                \in_array('restore-registry', $command, true) => $this->restore($command, (string) $inputFile),
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

    public function testAnUpdateIsWrittenDownWhileItSwaps(): void
    {
        $this->update();

        self::assertTrue($this->swapRecorded, 'While it swapped: what the machine\'s stop would leave.');
        self::assertFileDoesNotExist($this->root.'/backups/server/swap.json', 'Over: gone.');
        self::assertNull($this->updater('0.1.1')->cutShort());
    }

    public function testAnUpdateCutShortIsSaid(): void
    {
        new Filesystem()->dumpFile($this->root.'/backups/server/swap.json', '{"from": "0.1.1", "to": "0.2.0", "started_at": "2026-10-30T21:00:00+00:00"}');

        self::assertSame(['from' => '0.1.1', 'to' => '0.2.0'], $this->updater('0.1.1')->cutShort());
    }

    public function testUpdatingTheServer(): void
    {
        // That only server.env names the image, and not the updater's own environment, is the runner's
        // part: SymfonyProcessRunnerTest.
        $this->update();

        self::assertSame(self::NEW, $this->agent);
        self::assertSame(self::COMPOSE_020, file_get_contents($this->server.'/compose.yaml'));
        self::assertSame('command: 0.2.0', file_get_contents($this->server.'/bin/quire-server'));
        self::assertFileDoesNotExist($this->server.'/only-in-0.1.1.txt', 'The old release\'s files are gone.');
        self::assertFileExists($this->server.'/only-in-0.2.0.txt');
        // What install.sh checks when it's run again: the files as this update left them (SUPPLY-08)
        self::assertStringContainsString(hash('sha256', self::COMPOSE_020).'  ./compose.yaml', (string) file_get_contents($this->server.'/.installed-files'));
        self::assertStringNotContainsString('server.env', (string) file_get_contents($this->server.'/.installed-files'), 'The machine\'s own files aren\'t the release\'s.');

        // The machine's own files stay as they were; the release's copies of them are never used.
        self::assertSame('jane', file_get_contents($this->server.'/domains/jane.example'));
        self::assertSame('a deploy key', file_get_contents($this->server.'/keys/blog'));
        $env = (string) file_get_contents($this->server.'/server.env');
        self::assertStringContainsString("# Mine\n".self::SECRET."\n", $env);
        self::assertSame(self::NEW, $this->image());
        // For a release whose gate joins Docker's socket's group: read when the update reads the release
        self::assertSame((string) filegroup($this->root.'/docker.sock'), EnvFile::read($this->server.'/server.env')->get(ServerUpdater::DOCKER_GID));
        self::assertSame(0o600, fileperms($this->server.'/server.env') & 0o777);

        // Checked (its files, then its gate on the sites' scopes) and its image got while the server
        // runs; stopped only then, backed up, started.
        self::assertSame(['curl -fsSL', 'curl -fsSL', 'cosign verify-blob', 'curl -fsSL', 'tar -tzf', 'tar -tvzf', 'tar -xzf', 'docker compose exec', 'docker compose config', 'docker compose config', 'docker compose run', 'docker pull --quiet', 'docker compose stop', 'tar -cf', 'docker compose exec', 'cp -R', 'chmod -R', 'docker compose up', 'docker compose exec'], $this->runner->keys());

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

    public function testThePostgresMajorTheServerRunsStays(): void
    {
        $this->postgres = 19;
        $this->update();

        self::assertSame('compose.yaml:database/postgres-19.yaml', EnvFile::read($this->server.'/server.env')->get('COMPOSE_FILE'));
        self::assertFileExists($this->server.'/database/postgres-19.yaml');
    }

    public function testAReleaseThatNoLongerRunsTheServersPostgresChangesNothing(): void
    {
        $this->postgres = 17;
        $this->failsAndRuns('/doesn\'t run PostgreSQL 17 any more.*database:upgrade/', self::OLD);
        self::assertNotContains('docker compose stop', $this->runner->keys());
        self::assertSame(self::COMPOSE_011, file_get_contents($this->server.'/compose.yaml'));
    }

    public function testNoUpdateWhileAnUpgradeOfPostgresWasCutShort(): void
    {
        new DatabaseUpgrade(DatabaseUpgrade::directory($this->root).'/20261029-180000-000000', 18, 19, DatabaseUpgrade::COPYING, [], new \DateTimeImmutable())->save();

        $this->failsAndRuns('/cut short.*--recover/', self::OLD);
        self::assertNotContains('curl -fsSL', $this->runner->keys());
    }

    public function testAnUpdateBeforeAnUpgradeOfPostgresCannotBeUndone(): void
    {
        $this->update();
        $backup = $this->updater('0.2.0')->undoable();
        self::assertInstanceOf(ServerBackup::class, $backup);
        self::assertFalse($this->updater('0.2.0')->databaseChangedSince($backup));

        // PostgreSQL upgraded after it: its server.env would start the old major's data.
        new DatabaseUpgrade(DatabaseUpgrade::directory($this->root).'/20261029-190000-000000', 18, 19, DatabaseUpgrade::DONE, [], $backup->createdAt->modify('+1 minute'))->save();
        self::assertTrue($this->updater('0.2.0')->databaseChangedSince($backup));

        $this->expectException(ServerUpdateFailed::class);
        $this->expectExceptionMessageIsOrContains('PostgreSQL was upgraded');

        try {
            $this->updater('0.2.0')->undo($backup, 'cli', 'darko', static function (): void {});
        } finally {
            self::assertSame(self::NEW, $this->image(), 'Nothing was undone.');
        }
    }

    public function testComposeFilesThatDoNotWorkWithTheServersSettingsChangeNothing(): void
    {
        $this->composeValid = false;
        $this->failsAndRuns('/QUIRE_SERVER_NEW_SECRET/', self::OLD);
        self::assertNotContains('docker compose stop', $this->runner->keys());
    }

    public function testAGateThatRefusesASitesScopeChangesNothing(): void
    {
        $this->gateAccepts = false;
        $this->failsAndRuns('/Checking the sites\' scopes with the release\'s Docker gate.*deadbeefcafe\.json/s', self::OLD);
        self::assertNotContains('docker compose stop', $this->runner->keys());
        self::assertNotContains('docker pull --quiet', $this->runner->keys(), 'Not even its image is got.');
    }

    public function testAReleaseFromBeforeTheGateIsntCheckedByOne(): void
    {
        $this->releaseHasGate = false;
        $this->update();
        self::assertNotContains('docker compose run', $this->runner->keys());
        self::assertContains('docker compose up', $this->runner->keys(), 'It updates all the same.');
    }

    public function testTheGateChecksTheReleaseWhereDockerSeesIt(): void
    {
        // Left by an update a crash cut short: it holds a copy of server.env
        mkdir($this->root.'/server/'.ServerFolder::UPDATE_PREFIX.'cutshort', 0o700);
        file_put_contents($this->root.'/server/'.ServerFolder::UPDATE_PREFIX.'cutshort/server.env', "SECRET=x\n");

        $this->update();

        $check = $this->gateCheck ?? self::fail('The gate checked nothing.');
        // The same path in the updater as on the host (compose.yaml mounts the server's folder so):
        // Docker reads the release's own files from there, where /tmp was the updater's alone.
        self::assertStringStartsWith($this->root.'/server/'.ServerFolder::UPDATE_PREFIX, $check['directory']);
        self::assertStringContainsString(ServerUpdater::GATE_CONFIG."='".$check['directory'].'/gate/yorvogate.yaml'."'", $check['env'], 'The release\'s own settings for its gate.');
        self::assertStringNotContainsString(ServerUpdater::GATE_CONFIG, (string) file_get_contents($this->root.'/server/server.env'), 'Only for the check: never installed.');
        self::assertSame([], glob($this->root.'/server/'.ServerFolder::UPDATE_PREFIX.'*'), 'Nothing of it, or of the one cut short, is left.');
    }

    public function testAnUpdateKeepsTheKeyThatSignsBackupsAndTheWorkFolder(): void
    {
        mkdir($this->root.'/server/signing', 0o700);
        file_put_contents($this->root.'/server/signing/backups.key', "the-key\n");
        mkdir($this->root.'/server/work/jane', 0o700, true);

        $this->update();

        self::assertSame("the-key\n", file_get_contents($this->root.'/server/signing/backups.key'), 'Updates removed it with the release\'s files (0.4.0).');
        self::assertDirectoryExists($this->root.'/server/work/jane');
        $archives = glob($this->root.'/backups/server/*/files.tar');
        self::assertNotEmpty($archives);
        $listed = (string) shell_exec('tar -tf '.escapeshellarg($archives[0]));
        self::assertStringNotContainsString('signing/', $listed, 'Nor is it in the update\'s backup: it\'s in none of its backups (D-36).');
        self::assertStringNotContainsString(ServerFolder::UPDATE_PREFIX, $listed);
        self::assertStringNotContainsString('signing/', (string) file_get_contents($this->root.'/server/'.ServerFolder::MANIFEST));
    }

    public function testItWaitsForOperationsOnSites(): void
    {
        $other = $this->otherConnection();
        // An operation on a site runs (it holds the server's lock, shared).
        $other->fetchOne('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$this->schema.':server']);

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
            self::assertTrue(new SiteLock($this->connection, $this->schema)->lockServer());
            $this->expectExceptionMessageIsOrContains('The server is being updated');
            new SiteLock($other, $this->schema)->run(new SiteName('jane'), static fn (): bool => true);
        } finally {
            $other->close();
        }
    }

    public function testANewReleaseThatDoesNotStartIsRolledBack(): void
    {
        $this->failing = [self::NEW];
        $error = $this->failsAndRuns('/unhealthy.*\(the server runs as it was\)/s', self::OLD);

        self::assertTrue($error->restored);
        self::assertSame(self::COMPOSE_011, file_get_contents($this->server.'/compose.yaml'));
        self::assertFileExists($this->server.'/only-in-0.1.1.txt');
        self::assertFileDoesNotExist($this->server.'/only-in-0.2.0.txt');
        self::assertSame(self::OLD, $this->image());
        self::assertSame(0o600, fileperms($this->server.'/server.env') & 0o777);
        self::assertNotContains('restore-registry', array_merge(...array_map(static fn (array $run): array => $run['command'], $this->runner->runs)), 'Its migrations didn\'t run: the database stays.');
        self::assertSame('failed', $this->operations->latest()[0]['status']);
        self::assertContains('It runs as it was (0.1.1)', $this->operations->lines($this->operations->latest()[0]['id']));
        $other = $this->otherConnection();

        try {
            $ran = false;
            new SiteLock($other, $this->schema)->run(new SiteName('jane'), static function () use (&$ran): void {
                $ran = true;
            });
            self::assertTrue($ran, 'The server\'s lock is let go: sites can be worked on again.');
        } finally {
            $other->close();
        }
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
        self::assertSame(self::COMPOSE_011, file_get_contents($this->server.'/compose.yaml'));
        self::assertFileDoesNotExist($this->server.'/only-in-0.2.0.txt');
        self::assertSame(self::OLD, $this->image());
        self::assertSame(['Version1', 'Version2'], $this->migrationVersions());
        self::assertSame('a deploy key', file_get_contents($this->server.'/keys/blog'));
        self::assertSame(['server.undo', 'succeeded'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status']]);

        // The seal's key is the machine's: an update keeps it (else every update would leave the
        // updater taking any record, as before its first seal)
        self::assertFileExists($this->server.'/.updater-key');

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
        self::assertSame(self::COMPOSE_020, file_get_contents($this->server.'/compose.yaml'));
        self::assertInstanceOf(ServerBackup::class, $updated->undoable(), 'It can still be undone.');
    }

    public function testUndoingToBefore06TurnsOffTheUpdatesOfSitesWithThirdPartyPackages(): void
    {
        // L1: a release before 0.6 doesn't hold them where they are (G1), and would move them by night.
        $registry = new SiteRegistry($this->connection, $this->schema);
        $policies = new UpdatePolicies($this->connection, $this->schema);

        foreach (['jane', 'kim', 'lee'] as $site) {
            $registry->add(new SiteName($site), [new Domain($site.'.example')], Site::SHARED_DATABASE, $site);
        }

        $policies->set(new SiteName('jane'), UpdatePolicies::ALL);
        $policies->set(new SiteName('lee'), UpdatePolicies::OFF);
        $this->thirdParty = '{"jane": ["acme/reviews"], "lee": ["beta/gallery"]}';
        $this->update();

        $tester = $this->command('0.2.0', $policies);
        self::assertSame(Command::SUCCESS, $tester->execute(['--undo' => true, '--yes' => true]));

        self::assertSame(self::OLD, $this->agent, 'Undone.');
        self::assertSame([UpdatePolicies::OFF, UpdatePolicies::SECURITY, UpdatePolicies::OFF], [$policies->of(new SiteName('jane')), $policies->of(new SiteName('kim')), $policies->of(new SiteName('lee'))], 'Only those with third-party packages; kim\'s are as they were.');
        $said = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('The automatic updates of jane are off now: 0.1.1 doesn\'t hold third-party packages where they are', $said);
        self::assertStringContainsString('quire-server site:updates jane all', $said, 'How to turn them on again, as they were.');
        self::assertStringNotContainsString('site:updates lee', $said, 'Off already.');
    }

    public function testAnUndoThatFailsPutsTheirUpdatesBack(): void
    {
        $registry = new SiteRegistry($this->connection, $this->schema);
        $policies = new UpdatePolicies($this->connection, $this->schema);
        $registry->add(new SiteName('jane'), [new Domain('jane.example')], Site::SHARED_DATABASE, 'jane');
        $this->thirdParty = '{"jane": ["acme/reviews"]}';
        $this->update();
        $this->failing = [self::OLD];

        self::assertSame(Command::FAILURE, $this->command('0.2.0', $policies)->execute(['--undo' => true, '--yes' => true]));
        self::assertSame(UpdatePolicies::SECURITY, $policies->of(new SiteName('jane')), 'It runs 0.2.0, which holds them.');
    }

    public function testUndoingToBefore06PutsTheMachinesUpdatesBackAsItsSettingsHaveThem(): void
    {
        // What 0.6 wrote for the hour and the restart would stay: a release before it doesn't write them.
        $this->setting(['QUIRE_SERVER_REBOOT' => 'off']);
        $this->update();
        $this->setting(['QUIRE_SERVER_OS_UPDATE_HOUR' => '7', 'QUIRE_SERVER_REBOOT' => 'on']);
        $updated = $this->updater('0.2.0');
        $backup = $updated->undoable();
        self::assertInstanceOf(ServerBackup::class, $backup);
        $said = [];

        self::assertNull($updated->undo($backup, 'cli', 'darko', static function (string $line) use (&$said): void { $said[] = $line; }));

        self::assertSame(self::OLD, $this->agent, 'Undone.');
        self::assertSame(
            [['compose' => self::COMPOSE_020, 'env' => ['QUIRE_SERVER_OS_UPDATES' => '', 'QUIRE_SERVER_OS_UPDATE_HOUR' => '', 'QUIRE_SERVER_REBOOT' => 'off']]],
            $this->hostSetups,
            'Once, by the release undone from (the one undone to has no host-updates.sh), before its files went, with the settings of before the update: not set is the machine\'s own.',
        );
        self::assertContains('The machine\'s update hour and restart put back as 0.1.1\'s settings have them', $said);
    }

    public function testPuttingTheMachinesUpdatesBackFailingDoesntStopTheUndoAndIsSaidAfterIt(): void
    {
        $this->update();
        $this->hostSetupFails = true;
        $tester = $this->command('0.2.0', new UpdatePolicies($this->connection, $this->schema));

        self::assertSame(Command::SUCCESS, $tester->execute(['--undo' => true, '--yes' => true]));
        self::assertSame(self::OLD, $this->agent, 'Undone all the same.');
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        $after = substr($display, (int) strpos($display, 'It runs as before the update'));
        self::assertStringContainsString('The machine\'s update hour and restart are still as this release wrote them', $after, 'After the undo\'s own lines, where it isn\'t scrolled away.');
        self::assertStringContainsString('rm -f /etc/systemd/system/apt-daily-upgrade.timer.d/quire-server.conf /etc/apt/apt.conf.d/52quire-server-reboot && systemctl daemon-reload', $after);
    }

    public function testAnUndoThatFailsAfterPuttingTheMachinesUpdatesBackIsRolledBack(): void
    {
        $this->update();
        $this->failing = [self::OLD];
        $updated = $this->updater('0.2.0');
        $backup = $updated->undoable();
        self::assertInstanceOf(ServerBackup::class, $backup);

        try {
            $updated->undo($backup, 'cli', 'darko', static function (): void {});
            self::fail('It was undone.');
        } catch (ServerUpdateFailed $e) {
            self::assertTrue($e->restored);
        }

        // The rollback's start runs host-setup with server.env's settings again (compose.yaml), as at every start
        self::assertSame(self::NEW, $this->agent);
        self::assertCount(1, $this->hostSetups);
    }

    public function testOnlyAnUndoToBefore06PutsTheMachinesUpdatesBack(): void
    {
        $backup = static fn (string $from): ServerBackup => new ServerBackup('/nowhere', $from, '0.6.1', [], new \DateTimeImmutable());

        self::assertTrue(ServerUpdater::before06($backup('0.5.2')));
        self::assertTrue(ServerUpdater::before06($backup('0.4.0')));
        self::assertFalse(ServerUpdater::before06($backup('0.6.0-rc.3')), 'Its own start writes them, from the settings put back.');
        self::assertFalse(ServerUpdater::before06($backup('0.6.0')));
        self::assertFalse(ServerUpdater::before06($backup('0.6.1')));
    }

    public function testTheUndoNamesTheSettingsItPutsBack(): void
    {
        $this->setting(['QUIRE_SERVER_MAILER_DSN' => 'smtp://quire:old-password@smtp.example:587', 'QUIRE_SERVER_NAME' => 'fra1']);
        $this->update();
        $this->setting(['QUIRE_SERVER_OS_UPDATE_HOUR' => '7', 'QUIRE_SERVER_MAILER_DSN' => 'smtp://quire:new-password@smtp.example:587', 'QUIRE_SERVER_NOTIFY_SECRET' => 'whsec_new', 'QUIRE_SERVER_IMAGE' => self::NEW]);
        $updated = $this->updater('0.2.0');
        $backup = $updated->undoable();
        self::assertInstanceOf(ServerBackup::class, $backup);

        self::assertSame([
            'QUIRE_SERVER_MAILER_DSN' => ['now' => 'smtp://quire:(password)@smtp.example:587, changed', 'then' => 'smtp://quire:(password)@smtp.example:587'],
            'QUIRE_SERVER_NOTIFY_SECRET' => ['now' => 'set (not shown)', 'then' => '(not set)'],
            'QUIRE_SERVER_OS_UPDATE_HOUR' => ['now' => '7', 'then' => '(not set)'],
        ], $updated->settingsUndone($backup), 'Only the owner\'s settings that differ (not the image the update set), as "settings" shows them: a password only as changed.');

        $tester = $this->command('0.2.0', new UpdatePolicies($this->connection, $this->schema));
        $tester->setInputs(['no']);
        $tester->execute(['--undo' => true], ['interactive' => true]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('The server\'s settings go back as they were before the update. These change:', $display, 'Before it asks.');
        self::assertStringContainsString('QUIRE_SERVER_OS_UPDATE_HOUR: 7 → (not set)', $display);
        self::assertStringNotContainsString('new-password', $display);
        self::assertStringNotContainsString('whsec_new', $display);
        self::assertSame(self::NEW, $this->agent, 'Not undone: it was asked, and not answered.');
    }

    public function testSettingsThatCantBeToldAreSaidSo(): void
    {
        $this->update();
        $this->backupSettingsUnreadable = true;
        $tester = $this->command('0.2.0', new UpdatePolicies($this->connection, $this->schema));
        $tester->setInputs(['no']);
        $tester->execute(['--undo' => true], ['interactive' => true]);

        // An empty list only ever means "nothing changes"
        self::assertStringContainsString('Which settings change couldn\'t be told', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testAnAgentThatCantTellIsSaid(): void
    {
        $this->update();
        $this->thirdParty = null;
        $tester = $this->command('0.2.0', new UpdatePolicies($this->connection, $this->schema));

        self::assertSame(Command::SUCCESS, $tester->execute(['--undo' => true, '--yes' => true]));
        self::assertStringContainsString('Turn off the automatic updates of any site you added one to', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
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

    private function command(string $running, UpdatePolicies $policies): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ServerUpdateCommand($this->updater($running), true, $policies));

        return new CommandTester($application->find('server:update'));
    }

    private function updater(string $running): ServerUpdater
    {
        return new ServerUpdater(
            new Releases(new GitHubReleases($this->runner), $this->runner),
            new ServerFolder($this->runner, $this->root),
            new ServerStack($this->runner, 'unix:///var/run/docker.sock', 's3cret'),
            new SiteLock($this->connection, $this->schema),
            $this->connection,
            $this->operations,
            $this->root,
            $running,
            migrationsTable: $this->migrations,
            pause: static function (): void {},
            // Docker's socket, as the updater sees it: a file of the test's (its group is the test's)
            dockerSocket: $this->root.'/docker.sock',
        );
    }

    /**
     * Settings of server.env, as "settings:set" leaves them.
     *
     * @param array<string, string> $values
     */
    private function setting(array $values): void
    {
        file_put_contents($this->server.'/server.env', EnvFile::read($this->server.'/server.env')->with($values)->content());
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
        self::assertSame(['database', 'sh', '-c', ServerStack::RESTORE, 'restore-registry', 'quire_server'], \array_slice($command, -6), 'The registry is restored whole, by the script RegistryRestoreTest runs on a real PostgreSQL.');

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

    public function testABackupChangedAfterTheUpdateIsntUndoneTo(): void
    {
        $this->update();
        $updated = $this->updater('0.2.0');
        $backup = $updated->undoable();
        self::assertInstanceOf(ServerBackup::class, $backup);

        // What the agent could write: the backup's own files
        file_put_contents($backup->directory.'/backup.json', str_replace('0.1.1', '0.1.0', (string) file_get_contents($backup->directory.'/backup.json')));

        self::assertNull($updated->undoable(), 'Not sealed by the updater any more: not one to undo to.');
    }
}
