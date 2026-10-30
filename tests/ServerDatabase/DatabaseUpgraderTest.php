<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerDatabase;

use Doctrine\DBAL\DriverManager;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\SiteLock;
use Quire\Server\ServerDatabase\DatabaseCheck;
use Quire\Server\ServerDatabase\DatabaseContainers;
use Quire\Server\ServerDatabase\DatabaseUpgrade;
use Quire\Server\ServerDatabase\DatabaseUpgradeFailed;
use Quire\Server\ServerDatabase\DatabaseUpgrader;
use Quire\Server\ServerUpdate\ServerFolder;
use Quire\Server\ServerUpdate\ServerStack;
use Quire\Server\Site\EnvFile;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Upgrading the server's PostgreSQL, with Docker scripted as it would behave: the major that starts is
 * the one server.env names (if its data is there), each major's data is a folder on the volume, sites
 * are containers that stop and start; and the real files, registry and locks. Above all: what the
 * server runs when something fails, and that the old data is never touched.
 */
final class DatabaseUpgraderTest extends RegistryTestCase
{
    private const string SECRET = 'QUIRE_SERVER_POSTGRES_PASSWORD=s3cret';
    private const string JANE_PHP = 'aaaaaaaaaaaa';
    private const string JANE_WORKER = 'bbbbbbbbbbbb';

    private string $root;
    private string $server;
    private ScriptedRunner $runner;
    private OperationLog $operations;

    /** The major PostgreSQL runs, null when it's stopped */
    private ?int $postgres = 18;
    private bool $agent = true;

    /** @var list<int> The majors with data on the volume */
    private array $volume = [18];

    /** @var array<string, bool> Sites' containers on PostgreSQL's network, and whether each runs */
    private array $sites = [self::JANE_PHP => true, self::JANE_WORKER => true];

    /** The major started beside the server's, if any */
    private ?int $beside = null;

    private bool $copied = false;
    private bool $copyDiffers = false;
    private bool $newMajorFails = false;
    private bool $oldMajorFails = false;
    private string $stillConnected = '';
    private int $postgresTables = 0;
    private int $free = 100 << 30;

    /** @var list<string> */
    private array $said = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-server-database-'.bin2hex(random_bytes(6));
        $this->server = $this->root.'/server';
        $this->operations = new OperationLog($this->connection, $this->schema);
        PostgresImagesTest::release($this->server, [18 => PostgresImagesTest::PG18, 19 => PostgresImagesTest::PG19]);
        new Filesystem()->dumpFile($this->server.'/server.env', "# Mine\n".self::SECRET."\nCOMPOSE_FILE=compose.yaml\n");
        chmod($this->server.'/server.env', 0o600);

        $this->runner = new ScriptedRunner([
            'docker pull --quiet' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'docker compose stop' => function (array $command): ProcessResult {
                $this->agent = false;

                if (\in_array('database', $command, true)) {
                    $this->postgres = null;
                }

                return ScriptedRunner::ok($command);
            },
            'docker compose up' => function (array $command): ProcessResult {
                $major = str_contains((string) EnvFile::read($this->server.'/server.env')->get('COMPOSE_FILE'), 'postgres-19.yaml') ? 19 : 18;

                if (!\in_array($major, $this->volume, true) || (19 === $major && $this->newMajorFails) || (18 === $major && $this->oldMajorFails)) {
                    $this->postgres = null;

                    return ScriptedRunner::failed($command, "container quire-server-database-1 is unhealthy\n");
                }

                $this->postgres = $major;
                // Only the database, or every service
                $this->agent = $this->agent || 'database' !== end($command);

                return ScriptedRunner::ok($command);
            },
            'docker compose exec' => fn (array $command): ProcessResult => match (true) {
                \in_array('server:backups:now', $command, true) => ScriptedRunner::ok($command, "Backed up off the server: jane, the server.\n"),
                \in_array('psql', $command, true) => null === $this->postgres ? ScriptedRunner::failed($command, 'service "database" is not running') : ScriptedRunner::ok($command, $this->server($this->sql($command))),
                default => throw new \LogicException('Unexpected: '.implode(' ', $command)),
            },
            'docker ps --all' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, "dddddddddddd\n"),
            'docker ps --filter' => fn (array $command): ProcessResult => ScriptedRunner::ok($command, implode('', array_map(
                static fn (string $id): string => $id.' jane-'.(self::JANE_PHP === $id ? 'php' : 'worker')."-1 jane\n",
                array_keys(array_filter($this->sites)),
            )).($this->agent ? "cccccccccccc quire-server-agent-1 quire-server\n" : '')),
            'docker inspect --format' => fn (array $command): ProcessResult => match (end($command)) {
                'dddddddddddd' => ScriptedRunner::ok($command, "volume quire-server_database_data\n"),
                DatabaseContainers::BESIDE => null === $this->beside ? ScriptedRunner::failed($command, 'No such object') : ScriptedRunner::ok($command, "true\n"),
                default => throw new \LogicException('Unexpected: '.implode(' ', $command)),
            },
            'docker stop' => function (array $command): ProcessResult {
                foreach ($command as $part) {
                    if (isset($this->sites[$part])) {
                        $this->sites[$part] = false;
                    }
                }

                return ScriptedRunner::ok($command);
            },
            'docker start' => function (array $command): ProcessResult {
                self::assertNotNull($this->postgres, 'Sites start once PostgreSQL runs.');

                foreach ($command as $part) {
                    if (isset($this->sites[$part])) {
                        $this->sites[$part] = true;
                    }
                }

                return ScriptedRunner::ok($command);
            },
            'docker rm --force' => function (array $command): ProcessResult {
                $this->beside = null;

                return ScriptedRunner::ok($command);
            },
            'docker run --rm' => $this->helper(...),
            'docker run --detach' => function (array $command): ProcessResult {
                self::assertNotNull($this->postgres, 'The old major runs while it\'s copied.');
                self::assertSame([], array_filter($this->sites), 'Sites are stopped first.');
                self::assertFalse($this->agent, 'The agent is stopped first.');
                $this->beside = 19;
                $this->volume[] = 19;

                return ScriptedRunner::ok($command, "eeeeeeeeeeee\n");
            },
            'docker exec quire-server-database-upgrade' => fn (array $command): ProcessResult => match (true) {
                \in_array('pg_isready', $command, true) => ScriptedRunner::ok($command),
                default => ScriptedRunner::ok($command, $this->inventory($command)),
            },
            'docker exec --interactive' => function (array $command): ProcessResult {
                self::assertSame([DatabaseContainers::BESIDE, 'sh', '-s', '--', 'quire_server', 'site_jane'], \array_slice($command, 3));
                $this->copied = true;

                return ScriptedRunner::ok($command, "Copying the roles\nCopying quire_server\nCopying site_jane\n");
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testUpgradingPostgres(): void
    {
        $this->upgrader()->upgrade(19, 'cli', 'darko', $this->say(...));

        self::assertSame(19, $this->postgres);
        self::assertTrue($this->agent);
        self::assertSame([self::JANE_PHP => true, self::JANE_WORKER => true], $this->sites, 'The sites run again.');
        self::assertSame([18, 19], $this->volume, 'The old data stays.');
        self::assertNull($this->beside);

        // server.env names the new major; the rest as it was, root's only
        $env = (string) file_get_contents($this->server.'/server.env');
        self::assertStringContainsString("# Mine\n".self::SECRET."\n", $env);
        self::assertSame('compose.yaml:database/postgres-19.yaml', EnvFile::read($this->server.'/server.env')->get('COMPOSE_FILE'));
        self::assertSame(0o600, fileperms($this->server.'/server.env') & 0o777);

        $upgrade = $this->upgrader()->latest();
        self::assertInstanceOf(DatabaseUpgrade::class, $upgrade);
        self::assertSame([18, 19, DatabaseUpgrade::DONE, [self::JANE_PHP, self::JANE_WORKER]], [$upgrade->from, $upgrade->to, $upgrade->state, $upgrade->stopped]);
        self::assertInstanceOf(DatabaseUpgrade::class, $this->upgrader()->undoable());

        // The new major: beside the old one on its volume, in its own folder, not under PostgreSQL's alias
        $run = $this->lastRun('docker run --detach');
        self::assertContains('PGDATA=/quire-upgrade/19/docker', $run);
        self::assertContains('quire-server_database_data:/quire-upgrade', $run);
        self::assertContains(PostgresImagesTest::PG19, $run);
        self::assertNotContains('--network-alias', $run);

        // Passwords only by name, from the environment
        foreach ($this->runner->runs as $run) {
            self::assertStringNotContainsString('s3cret', implode(' ', $run['command']));
        }

        self::assertContains('The same in both: the roles and their rights, 2 databases, 3 tables, 5004 rows', $this->said);
        $operation = $this->operations->latest()[0];
        self::assertSame(['server.database-upgrade', 'succeeded', 'darko'], [$operation['action'], $operation['status'], $operation['requested_by']]);
        self::assertSame($this->said, $this->operations->lines($operation['id']));
    }

    public function testEverySiteIsBackedUpOffTheServerFirstWhenThatIsSetUp(): void
    {
        $this->upgrader(new BackupSettings('s3:https://s3.example.com/bucket', 'key', 'secret', 'password', 'us-east-1', 'quire-server', '2', '7 daily'))->upgrade(19, 'cli', 'darko', $this->say(...));

        $keys = $this->runner->keys();
        self::assertLessThan(array_search('docker compose stop', $keys, true), array_search('docker compose exec', $keys, true));
        self::assertContains('  Backed up off the server: jane, the server.', $this->said);
    }

    public function testACopyThatDiffersIsRolledBack(): void
    {
        $this->copyDiffers = true;
        $error = $this->fails('/The copy differs from the original: site_jane: relation quire\.content \(different or added\).*\(the server runs as it was\)/');

        self::assertTrue($error->restored);
        $this->assertRunsAsBefore();
        self::assertNotContains('docker compose stop', \array_slice($this->runner->keys(), (int) array_search('docker exec --interactive', $this->runner->keys(), true)), 'The old major was never stopped.');
    }

    public function testANewMajorThatDoesNotStartIsRolledBack(): void
    {
        $this->newMajorFails = true;
        $error = $this->fails('/unhealthy/');

        self::assertTrue($error->restored);
        $this->assertRunsAsBefore();
        self::assertContains('Switching to PostgreSQL 19', $this->said);
    }

    public function testNothingElseMayWriteWhileItIsCopied(): void
    {
        $this->stillConnected = 'someone on site_jane';
        $this->fails('/Something still uses the server\'s PostgreSQL \(someone on site_jane\)/');

        $this->assertRunsAsBefore();
        self::assertFalse($this->copied);
    }

    public function testWhatCannotBeDoneChangesNothing(): void
    {
        foreach ([
            [18, null, 'runs PostgreSQL 18.6 already'],
            [20, null, 'doesn\'t run PostgreSQL 20'],
            [19, fn (): int => $this->postgresTables = 2, '"postgres" holds tables'],
            [19, function (): void {
                $this->postgresTables = 0;
                $this->free = 1 << 20;
            }, 'The copy needs about 1.1 GB on the server and 1.0 MB is left'],
        ] as [$to, $setUp, $message]) {
            $this->runner->runs = [];

            if (null !== $setUp) {
                $setUp();
            }

            try {
                $this->upgrader()->upgrade($to, 'cli', 'darko', $this->say(...));
                self::fail('It upgraded.');
            } catch (\DomainException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }

            self::assertNotContains('docker compose stop', $this->runner->keys());
            self::assertSame('failed', $this->operations->latest()[0]['status']);
        }

        self::assertSame(18, $this->postgres);
        self::assertNull($this->upgrader()->latest());
    }

    public function testItWaitsForOperationsOnSites(): void
    {
        /** @var array{driver: 'pdo_pgsql'} $params The same database, through a connection of its own */
        $params = $this->connection->getParams();
        $other = DriverManager::getConnection($params);
        $other->fetchOne('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [SiteLock::SERVER]);

        try {
            $this->upgrader()->upgrade(19, 'cli', 'darko', $this->say(...));
            self::fail('It upgraded.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('An operation on a site runs', $e->getMessage());
        } finally {
            $other->close();
        }

        self::assertNotContains('docker compose stop', $this->runner->keys());
    }

    public function testUndoingAnUpgrade(): void
    {
        $this->upgrader()->upgrade(19, 'cli', 'darko', $this->say(...));
        $upgrade = $this->upgrader()->undoable();
        self::assertInstanceOf(DatabaseUpgrade::class, $upgrade);

        $this->upgrader()->undo($upgrade, 'cli', 'darko', $this->say(...));

        self::assertSame(18, $this->postgres);
        self::assertSame('compose.yaml', EnvFile::read($this->server.'/server.env')->get('COMPOSE_FILE'));
        self::assertSame([18, 19], $this->volume, 'What was written to 19 stays until it\'s cleaned.');
        self::assertSame([self::JANE_PHP => true, self::JANE_WORKER => true], $this->sites);
        self::assertTrue($this->agent);
        self::assertSame(DatabaseUpgrade::UNDONE, $this->upgrader()->latest()?->state);
        self::assertNull($this->upgrader()->undoable());
        self::assertSame('server.database-undo', $this->operations->latest()[0]['action']);

        // Upgrading again starts from a fresh copy.
        $this->upgrader()->upgrade(19, 'cli', 'darko', $this->say(...));
        self::assertContains('Removing PostgreSQL 19\'s data from an earlier attempt', $this->said);
        self::assertSame(19, $this->postgres);
    }

    public function testAnUndoThatFailsGoesForwardAgain(): void
    {
        $this->upgrader()->upgrade(19, 'cli', 'darko', $this->say(...));
        $upgrade = $this->upgrader()->undoable();
        self::assertInstanceOf(DatabaseUpgrade::class, $upgrade);
        $this->oldMajorFails = true;

        try {
            $this->upgrader()->undo($upgrade, 'cli', 'darko', $this->say(...));
            self::fail('It was undone.');
        } catch (DatabaseUpgradeFailed $e) {
            self::assertTrue($e->restored);
            self::assertStringContainsString('unhealthy', $e->getMessage());
        }

        self::assertSame(19, $this->postgres);
        self::assertSame('compose.yaml:database/postgres-19.yaml', EnvFile::read($this->server.'/server.env')->get('COMPOSE_FILE'));
        self::assertSame([18, 19], $this->volume, 'Neither major\'s data is touched.');
        self::assertSame([self::JANE_PHP => true, self::JANE_WORKER => true], $this->sites);
        self::assertTrue($this->agent);
        self::assertSame(DatabaseUpgrade::DONE, $this->upgrader()->latest()?->state, 'It can still be undone.');
    }

    public function testAnUndoWithoutTheOldDataChangesNothing(): void
    {
        $this->upgrader()->upgrade(19, 'cli', 'darko', $this->say(...));
        $upgrade = $this->upgrader()->undoable();
        self::assertInstanceOf(DatabaseUpgrade::class, $upgrade);
        // 18's data is gone (by hand): it can't start
        $this->volume = [19];

        try {
            $this->upgrader()->undo($upgrade, 'cli', 'darko', $this->say(...));
            self::fail('It was undone.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('PostgreSQL 18\'s data isn\'t there any more', $e->getMessage());
        }

        self::assertSame(19, $this->postgres);
        self::assertSame(DatabaseUpgrade::DONE, $this->upgrader()->latest()?->state);
    }

    public function testAnUpgradeCutShortIsPutBack(): void
    {
        // The machine restarted while it copied: sites and agent stopped, the new major half filled
        $this->agent = false;
        $this->sites = [self::JANE_PHP => false, self::JANE_WORKER => false];
        $this->beside = 19;
        $this->volume = [18, 19];
        $upgrade = new DatabaseUpgrade(DatabaseUpgrade::directory($this->root).'/20261029-180000-000000', 18, 19, DatabaseUpgrade::COPYING, [self::JANE_PHP, self::JANE_WORKER], new \DateTimeImmutable());
        $upgrade->save();

        try {
            $this->upgrader()->upgrade(19, 'cli', 'darko', $this->say(...));
            self::fail('It upgraded.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('--recover', $e->getMessage());
        }

        $this->upgrader()->recover($upgrade, 'cli', 'darko', $this->say(...));

        $this->assertRunsAsBefore();
        self::assertSame('server.database-recover', $this->operations->latest()[0]['action']);
    }

    public function testAnUpgradeCutShortAfterTheSwitchIsFinished(): void
    {
        // server.env names 19, and after the machine restarted the sites ran on it (and wrote to it); the
        // agent didn't start
        $this->agent = false;
        $this->postgres = 19;
        $this->sites = [self::JANE_PHP => true, self::JANE_WORKER => true];
        $this->volume = [18, 19];
        new Filesystem()->dumpFile($this->server.'/server.env', "# Mine\n".self::SECRET."\nCOMPOSE_FILE=compose.yaml:database/postgres-19.yaml\n");
        $upgrade = new DatabaseUpgrade(DatabaseUpgrade::directory($this->root).'/20261029-180000-000000', 18, 19, DatabaseUpgrade::SWITCHED, [self::JANE_PHP, self::JANE_WORKER], new \DateTimeImmutable());
        $upgrade->save();

        $this->upgrader()->recover($upgrade, 'cli', 'darko', $this->say(...));

        self::assertSame(19, $this->postgres, 'Forward, on the major the sites wrote to.');
        self::assertSame([18, 19], $this->volume, 'Nothing removed: 19 holds their writes, 18 is kept for an undo.');
        self::assertStringContainsString('database/postgres-19.yaml', (string) file_get_contents($this->server.'/server.env'));
        self::assertSame([self::JANE_PHP => true, self::JANE_WORKER => true], $this->sites);
        self::assertTrue($this->agent);
        self::assertSame(DatabaseUpgrade::DONE, $this->upgrader()->latest()?->state);
        self::assertSame('server.database-recover', $this->operations->latest()[0]['action']);
        self::assertNotNull($this->upgrader()->undoable(), 'It can still be undone.');
    }

    public function testAnUpgradeCutShortAtTheSwitchIsFinished(): void
    {
        // Cut while switching: server.env names 19, PostgreSQL stopped, sites and agent too
        $this->agent = false;
        $this->postgres = null;
        $this->sites = [self::JANE_PHP => false, self::JANE_WORKER => false];
        $this->volume = [18, 19];
        new Filesystem()->dumpFile($this->server.'/server.env', "# Mine\n".self::SECRET."\nCOMPOSE_FILE=compose.yaml:database/postgres-19.yaml\n");
        $upgrade = new DatabaseUpgrade(DatabaseUpgrade::directory($this->root).'/20261029-180000-000000', 18, 19, DatabaseUpgrade::SWITCHED, [self::JANE_PHP, self::JANE_WORKER], new \DateTimeImmutable());
        $upgrade->save();

        $this->upgrader()->recover($upgrade, 'cli', 'darko', $this->say(...));

        self::assertSame(19, $this->postgres);
        self::assertSame([18, 19], $this->volume);
        self::assertSame([self::JANE_PHP => true, self::JANE_WORKER => true], $this->sites);
        self::assertSame(DatabaseUpgrade::DONE, $this->upgrader()->latest()?->state);
    }

    public function testAnUndoCutShortIsFinished(): void
    {
        $this->agent = false;
        $this->postgres = null;
        $this->sites = [self::JANE_PHP => false, self::JANE_WORKER => true];
        $this->volume = [18, 19];
        new Filesystem()->dumpFile($this->server.'/server.env', "# Mine\n".self::SECRET."\nCOMPOSE_FILE=compose.yaml:database/postgres-19.yaml\n");
        $upgrade = new DatabaseUpgrade(DatabaseUpgrade::directory($this->root).'/20261029-180000-000000', 18, 19, DatabaseUpgrade::UNDOING, [self::JANE_PHP], new \DateTimeImmutable());
        $upgrade->save();

        $this->upgrader()->recover($upgrade, 'cli', 'darko', $this->say(...));

        self::assertSame(18, $this->postgres);
        self::assertSame([18, 19], $this->volume, '19\'s data holds what sites wrote after the upgrade: it stays.');
        self::assertSame([self::JANE_PHP => true, self::JANE_WORKER => true], $this->sites);
        self::assertSame(DatabaseUpgrade::UNDONE, $this->upgrader()->latest()?->state);
    }

    public function testCleaningRemovesTheMajorsThatDoNotRun(): void
    {
        $this->upgrader()->upgrade(19, 'cli', 'darko', $this->say(...));

        self::assertSame([18], $this->upgrader()->clean('cli', 'darko', $this->say(...), dryRun: true));
        self::assertSame([18, 19], $this->volume);
        self::assertSame([18], $this->upgrader()->clean('cli', 'darko', $this->say(...)));
        self::assertSame([19], $this->volume);
        self::assertSame([], $this->upgrader()->clean('cli', 'darko', $this->say(...)));
        self::assertSame(19, $this->postgres);
    }

    private function assertRunsAsBefore(): void
    {
        self::assertSame(18, $this->postgres);
        self::assertTrue($this->agent);
        self::assertSame([self::JANE_PHP => true, self::JANE_WORKER => true], $this->sites, 'The sites run again.');
        self::assertSame([18], $this->volume, 'The unfinished copy is gone.');
        self::assertNull($this->beside);
        self::assertSame('compose.yaml', EnvFile::read($this->server.'/server.env')->get('COMPOSE_FILE'));
        self::assertSame(DatabaseUpgrade::FAILED, $this->upgrader()->latest()?->state);
    }

    private function fails(string $message): DatabaseUpgradeFailed
    {
        try {
            $this->upgrader()->upgrade(19, 'cli', 'darko', $this->say(...));
            self::fail('It upgraded.');
        } catch (DatabaseUpgradeFailed $e) {
            self::assertMatchesRegularExpression($message, $e->getMessage());
            self::assertSame('failed', $this->operations->latest()[0]['status']);

            return $e;
        }
    }

    private function upgrader(?BackupSettings $backups = null): DatabaseUpgrader
    {
        $runner = $this->runner;

        return new DatabaseUpgrader(
            new ServerFolder($runner, $this->root),
            new ServerStack($runner, 'unix:///var/run/docker.sock', 's3cret'),
            new DatabaseContainers($runner, 'unix:///var/run/docker.sock', 's3cret', static function (): void {}),
            new SiteLock($this->connection),
            $this->connection,
            $this->operations,
            $backups ?? new BackupSettings('', '', '', '', 'us-east-1', 'quire-server', '2', '7 daily'),
            $this->root,
            pause: static function (): void {},
        );
    }

    private function say(string $line): void
    {
        $this->said[] = $line;
    }

    /**
     * @return list<string> The arguments of the last command run with that key
     */
    private function lastRun(string $key): array
    {
        $runs = array_values(array_filter($this->runner->runs, static fn (array $run): bool => ScriptedRunner::key($run['command']) === $key));

        return end($runs)['command'] ?? [];
    }

    /**
     * @param list<string> $command
     */
    private function sql(array $command): string
    {
        foreach ($command as $part) {
            if (str_starts_with($part, '--command=')) {
                return substr($part, \strlen('--command='));
            }
        }

        return '';
    }

    /**
     * What the server's PostgreSQL answers.
     */
    private function server(string $sql): string
    {
        return match (true) {
            str_contains($sql, 'server_version_num') => \sprintf("%d0006 %d.6\n", $this->postgres, $this->postgres),
            DatabaseCheck::DATABASES === $sql => "quire_server|true\nsite_jane|true\n",
            DatabaseCheck::POSTGRES_DATABASE === $sql => $this->postgresTables."\n",
            DatabaseCheck::SIZE === $sql => "73400320\n",
            str_contains($sql, 'pg_stat_activity') => $this->stillConnected."\n",
            default => throw new \LogicException('Unexpected query: '.$sql),
        };
    }

    /**
     * What each major has, as DatabaseCheck lists it (the old one asked through the network).
     *
     * @param list<string> $command
     */
    private function inventory(array $command): string
    {
        $old = \in_array('--host=quire-database', $command, true);

        return match (true) {
            DatabaseCheck::CLUSTER === $this->sql($command) => "role postgres t 7e79\nrole site_jane f 9b22\n",
            \in_array('--dbname=quire_server', $command, true) => "relation server.site r rows 1\n",
            default => \sprintf("relation quire.content r rows %d\nrelation quire.user r rows 3\n", !$old && $this->copyDiffers ? 4999 : 5000),
        };
    }

    /**
     * A one-program container on PostgreSQL's volume.
     *
     * @param list<string> $command
     */
    private function helper(array $command): ProcessResult
    {
        $program = $command[(int) array_search('--entrypoint', $command, true) + 1] ?? '';

        if ('rm' === $program) {
            $major = (int) basename((string) end($command));
            self::assertNotSame($this->postgres, $major, 'The data of the major that runs is never removed.');
            self::assertNull($this->beside, 'Nothing uses it.');
            $this->volume = array_values(array_diff($this->volume, [$major]));
        }

        return match ($program) {
            'df' => ScriptedRunner::ok($command, \sprintf("Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 104857600 1048576 %d 2%% /quire-upgrade\n", intdiv($this->free, 1024))),
            'ls' => ScriptedRunner::ok($command, implode("\n", $this->volume)."\nlost+found\n"),
            'rm' => ScriptedRunner::ok($command),
            default => throw new \LogicException('Unexpected: '.implode(' ', $command)),
        };
    }
}
