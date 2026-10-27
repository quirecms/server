<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use Quire\Server\Database\SiteDatabases;
use Quire\Server\Docker\Compose;
use Quire\Server\Docker\ComposePolicy;
use Quire\Server\Docker\Images;
use Quire\Server\Docker\SkeletonInstaller;
use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\SiteUpdates;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Update\SiteUpdater;
use Quire\Server\Update\SkeletonFiles;
use Quire\Server\Update\UpdateFailed;
use Quire\Server\Update\UpdatePlan;
use Quire\Server\Update\UpdatePlanner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Updating a site and undoing it, with Docker scripted as it would behave (which image each name
 * points to, which one runs, how many migrations the running one's database has), and the real
 * registry and files: above all, what the site is left with when something fails.
 */
final class SiteUpdaterTest extends RegistryTestCase
{
    private const string OLD = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';
    private const string NEW = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    private string $root;
    private SiteName $name;
    private ServerPaths $paths;
    private SiteRegistry $registry;
    private SiteUpdates $updates;
    private OperationLog $operations;
    private ScriptedRunner $runner;

    /** @var array<string, string> Image name => ID */
    private array $tags = ['jane-php-prod' => self::OLD];

    /** The image the site's containers run */
    private string $running = self::OLD;

    /** @var array<string, int> Migrations the database has run, by the image that ran them */
    private array $migrations = [self::OLD => 19, self::NEW => 19];
    private int $executed = 19;
    private bool $buildFails = false;

    /** @var list<string> Images that answer the smoke check */
    private array $healthy = [self::OLD, self::NEW];

    /** How many "up" may still succeed (null: all) */
    private ?int $ups = null;

    /** @var list<string> Backups restored */
    private array $restored = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-updater-'.bin2hex(random_bytes(6));
        $this->paths = new ServerPaths($this->root);
        $this->name = new SiteName('jane');
        $this->registry = new SiteRegistry($this->connection, $this->schema);
        $this->updates = new SiteUpdates($this->connection, $this->schema);
        $this->operations = new OperationLog($this->connection, $this->schema);
        $this->registry->add($this->name, [new Domain('jane.example')], Site::SHARED_DATABASE, 'jane');
        $this->registry->setStatus($this->name, Site::RUNNING);

        $site = $this->paths->site($this->name);
        $filesystem = new Filesystem();
        $filesystem->dumpFile($site.'/composer.json', '{"require": {"quire/core": "^0.1"}}');
        $filesystem->dumpFile($site.'/composer.lock', LockTest::lock(['quire/core' => 'v0.1.0'])->content);
        $filesystem->dumpFile($site.'/Dockerfile', 'FROM 0.1.0');
        $filesystem->dumpFile($site.'/'.SiteFiles::ENV_FILE, "COMPOSE_PROJECT_NAME='jane'\n");

        $this->runner = new ScriptedRunner([
            'docker compose config' => fn (array $command): ProcessResult => ScriptedRunner::ok($command, json_encode($this->config(), \JSON_THROW_ON_ERROR)),
            'docker compose build' => function (array $command): ProcessResult {
                if ($this->buildFails) {
                    return ScriptedRunner::failed($command, "composer install: your requirements could not be resolved\n");
                }

                $this->tags['jane-php-prod'] = self::NEW;

                return ScriptedRunner::ok($command);
            },
            'docker compose up' => function (array $command): ProcessResult {
                if (null !== $this->ups && $this->ups-- <= 0) {
                    return ScriptedRunner::failed($command, "container jane-php-1 is unhealthy\n");
                }

                $this->running = $this->tags['jane-php-prod'];
                // Migrations run as it starts.
                $this->executed = max($this->executed, $this->migrations[$this->running]);

                return ScriptedRunner::ok($command);
            },
            'docker compose exec' => fn (array $command, ?string $directory, ?string $inputFile): ProcessResult => match ($command[11] ?? '') {
                'php' => \in_array($this->running, $this->healthy, true) ? ScriptedRunner::ok($command) : ScriptedRunner::failed($command, "/_quire/health: 500\n"),
                'bin/console' => match ($command[12] ?? '') {
                    'quire:about' => ScriptedRunner::ok($command, (string) json_encode(['contract' => 1, 'migrations' => ['executed' => $this->executed, 'pending' => 0]])),
                    'quire:restore' => $this->restore($command, $inputFile),
                    default => ScriptedRunner::ok($command),
                },
                default => ScriptedRunner::ok($command),
            },
            'docker compose cp' => static function (array $command): ProcessResult {
                // Out of the site: its backup. (Nothing is copied into it.)
                self::assertStringStartsWith('php:', $command[9]);
                file_put_contents($command[10], 'backup');

                return ScriptedRunner::ok($command);
            },
            'docker image inspect' => fn (array $command): ProcessResult => isset($this->tags[$command[5]]) ? ScriptedRunner::ok($command, $this->tags[$command[5]]."\n") : ScriptedRunner::failed($command, 'No such image'),
            'docker image tag' => function (array $command): ProcessResult {
                $this->tags[$command[4]] = $command[3];

                return ScriptedRunner::ok($command);
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testUpdatingASite(): void
    {
        $said = [];
        $this->updater()->update($this->plan(), false, 'cli', 'darko', static function (string $step) use (&$said): void {
            $said[] = $step;
        });

        self::assertSame(['Keeping its files as they are, to put them back', 'Changing its files', 'Checking and building the new version (a few minutes; it keeps running meanwhile)', 'Backing it up', 'Starting the new version (a few seconds offline)', 'Checking it'], $said);
        self::assertSame(self::NEW, $this->running);
        self::assertSame(self::OLD, $this->tags['jane-php-prod:before-update'], 'The image it ran keeps a name: nothing prunes it while the update can be undone.');
        $site = $this->paths->site($this->name);
        self::assertStringContainsString('v0.1.1', (string) file_get_contents($site.'/composer.lock'));
        self::assertSame('FROM 0.1.1', file_get_contents($site.'/Dockerfile'));

        // Built while the old version runs; backed up just before it's stopped; checked after.
        $order = array_values(array_filter($this->runner->keys(), static fn (string $key): bool => \in_array($key, ['docker compose build', 'docker compose up', 'docker compose cp'], true)));
        self::assertSame(['docker compose build', 'docker compose cp', 'docker compose up'], $order);

        $update = $this->updates->undoable($this->name);
        self::assertNotNull($update);
        self::assertSame([self::OLD, self::NEW, false, 'v0.1.0', 'v0.1.1'], [$update['image_before'], $update['image_after'], $update['migrations_ran'], $update['quire_from'], $update['quire_to']]);
        self::assertSame(['site.update', 'succeeded'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status']]);
    }

    public function testAFailedBuildChangesNothing(): void
    {
        $this->buildFails = true;
        $site = $this->paths->site($this->name);
        $before = [(string) file_get_contents($site.'/composer.lock'), (string) file_get_contents($site.'/Dockerfile')];

        try {
            $this->updater()->update($this->plan(), false, 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (UpdateFailed $e) {
            self::assertTrue($e->restored);
            self::assertStringContainsString('could not be resolved', $e->getMessage());
        }

        self::assertSame($before, [(string) file_get_contents($site.'/composer.lock'), (string) file_get_contents($site.'/Dockerfile')], 'Its files as they were.');
        self::assertSame(self::OLD, $this->tags['jane-php-prod']);
        self::assertNotContains('docker compose up', $this->runner->keys(), 'Never stopped.');
        self::assertNotContains('docker compose cp', $this->runner->keys());
        self::assertNull($this->updates->undoable($this->name));
        self::assertSame(Site::RUNNING, $this->registry->get($this->name)?->status);
    }

    public function testAVersionThatDoesntAnswerIsRolledBackWithItsBackupWhenItsMigrationsRan(): void
    {
        $this->healthy = [self::OLD];
        $this->migrations[self::NEW] = 21;

        try {
            $this->updater()->update($this->plan(), false, 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (UpdateFailed $e) {
            self::assertTrue($e->restored, 'Rolled back.');
        }

        self::assertSame(self::OLD, $this->running, 'The image it had runs again.');
        self::assertCount(1, $this->restored, 'Its database as before the update: the new migrations ran.');
        self::assertStringContainsString('/backups/jane/update-', $this->restored[0]);
        self::assertSame('FROM 0.1.0', file_get_contents($this->paths->site($this->name).'/Dockerfile'));
        self::assertStringContainsString('v0.1.0', (string) file_get_contents($this->paths->site($this->name).'/composer.lock'));
        self::assertSame(Site::RUNNING, $this->registry->get($this->name)?->status);
        $operation = $this->operations->latest()[0];
        self::assertSame('failed', $operation['status']);
        self::assertStringStartsWith('The update failed and was rolled back', (string) $operation['error']);
    }

    public function testWithoutNewMigrationsTheRollBackKeepsTheDatabase(): void
    {
        $this->healthy = [self::OLD];

        try {
            $this->updater()->update($this->plan(), false, 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (UpdateFailed) {
        }

        self::assertSame(self::OLD, $this->running);
        self::assertSame([], $this->restored, 'Nothing changed in its database: nothing lost to a restore.');
    }

    public function testWhenRollingBackFailsTooTheSiteIsMarkedFailed(): void
    {
        $this->healthy = [];
        $this->ups = 1;

        try {
            $this->updater()->update($this->plan(), false, 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (UpdateFailed $e) {
            self::assertFalse($e->restored);
        }

        self::assertSame(Site::FAILED, $this->registry->get($this->name)?->status);
        self::assertStringContainsString('Its backup: '.$this->paths->backups($this->name).'/update-', (string) $this->operations->latest()[0]['error'], 'Where its backup is, for a person.');
    }

    public function testUndoingAnUpdateThatRanNoMigrations(): void
    {
        $updater = $this->updater();
        $updater->update($this->plan(), false, 'cli', 'darko', static fn (): null => null);

        $updater->undo($this->name, false, 'cli', 'darko', static fn (): null => null);

        self::assertSame(self::OLD, $this->running);
        self::assertSame([], $this->restored, 'Code only: nothing lost.');
        self::assertSame('FROM 0.1.0', file_get_contents($this->paths->site($this->name).'/Dockerfile'));
        self::assertStringContainsString('v0.1.0', (string) file_get_contents($this->paths->site($this->name).'/composer.lock'));
        self::assertNull($this->updates->undoable($this->name), 'Undone once.');
        self::assertSame(['site.undo-update', 'succeeded'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status']]);
    }

    public function testUndoingAnUpdateThatChangedTheDatabaseRestoresItsBackupOnlyWhenAllowed(): void
    {
        $this->migrations[self::NEW] = 21;
        $updater = $this->updater();
        $updater->update($this->plan(), false, 'cli', 'darko', static fn (): null => null);

        try {
            $updater->undo($this->name, false, 'cli', 'darko', static fn (): null => null);
            self::fail('Changes would have been lost unasked.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('its changes since are lost', $e->getMessage());
        }

        self::assertSame(self::NEW, $this->running, 'Nothing changed.');

        $updater->undo($this->name, true, 'cli', 'darko', static fn (): null => null);

        self::assertSame(self::OLD, $this->running);
        self::assertCount(1, $this->restored);
        self::assertStringContainsString('/backups/jane/update-', $this->restored[0], 'The backup from before the update.');
    }

    public function testAPlanIsAppliedOnlyToTheFilesItWasMadeFrom(): void
    {
        $plan = $this->plan();
        file_put_contents($this->paths->site($this->name).'/composer.json', '{"require": {"quire/core": "^0.2"}}');

        $this->expectExceptionMessageIsOrContains('changed since the update was planned');

        try {
            $this->updater()->update($plan, false, 'cli', 'darko', static fn (): null => null);
        } finally {
            self::assertSame([], $this->runner->runs, 'Nothing run.');
            self::assertDirectoryDoesNotExist($plan->workspace, 'The plan is discarded.');
        }
    }

    public function testFilesTheSiteEditedAreNeverOverwrittenUnasked(): void
    {
        $plan = $this->plan([['path' => 'Dockerfile', 'action' => SkeletonFiles::CONFLICT]]);

        try {
            $this->updater()->update($plan, false, 'cli', 'darko', static fn (): null => null);
            self::fail('It wasn\'t refused.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Dockerfile', $e->getMessage());
        }

        self::assertSame([], $this->runner->runs);

        // Kept as the site has it, when asked.
        $this->updater()->update($this->plan([['path' => 'Dockerfile', 'action' => SkeletonFiles::CONFLICT]]), true, 'cli', 'darko', static fn (): null => null);
        self::assertSame('FROM 0.1.0', file_get_contents($this->paths->site($this->name).'/Dockerfile'));
        self::assertSame(self::NEW, $this->running);
    }

    /**
     * @param list<array{path: string, action: string}> $skeletonFiles
     */
    private function plan(array $skeletonFiles = [['path' => 'Dockerfile', 'action' => SkeletonFiles::REPLACE]]): UpdatePlan
    {
        $workspace = $this->root.'/plan-'.bin2hex(random_bytes(4));
        new Filesystem()->dumpFile($workspace.'/skeleton-new/Dockerfile', 'FROM 0.1.1');
        $from = LockTest::lock(['quire/core' => 'v0.1.0']);
        $to = LockTest::lock(['quire/core' => 'v0.1.1']);

        return new UpdatePlan($this->name, $workspace, $from, $to, '{"require": {"quire/core": "^0.1.1"}}', $from->changesTo($to), $skeletonFiles, $workspace.'/skeleton-new', true, [], [], UpdatePlanner::siteFiles($this->paths->site($this->name)));
    }

    private function updater(): SiteUpdater
    {
        $compose = new Compose($this->runner, new ComposePolicy(), 'tcp://docker-proxy:2375');
        $sites = new SiteManager(
            $this->registry,
            $this->operations,
            new SiteLock($this->connection),
            new SkeletonInstaller($this->runner),
            new SiteDatabases($this->connection),
            new SiteFiles(),
            $compose,
            new DomainDirectory($this->paths->domains()),
            $this->paths,
            '',
        );

        return new SiteUpdater($this->registry, $this->updates, $this->operations, new SiteLock($this->connection), $sites, $compose, new Images($this->runner, 'tcp://docker-proxy:2375'), $this->paths);
    }

    /**
     * @param list<string> $command
     */
    private function restore(array $command, ?string $inputFile): ProcessResult
    {
        // Streamed in: "quire:restore -".
        self::assertSame('-', $command[13] ?? null);
        self::assertNotNull($inputFile);
        $this->restored[] = $inputFile;
        $this->executed = $this->migrations[self::OLD];

        return ScriptedRunner::ok($command);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $directory = $this->paths->site($this->name);
        $build = ['context' => $directory, 'dockerfile' => 'Dockerfile'];

        return [
            'name' => 'jane',
            'networks' => ['default' => ['name' => 'jane_default'], 'quire-edge' => ['name' => 'quire-edge', 'external' => true], 'quire-data' => ['name' => 'quire-data', 'external' => true]],
            'services' => [
                'php' => ['image' => 'jane-php-prod', 'build' => $build, 'networks' => ['default' => null, 'quire-edge' => ['aliases' => ['jane.example']], 'quire-data' => null]],
                'worker' => ['image' => 'jane-php-prod', 'build' => $build, 'networks' => ['default' => null, 'quire-data' => null]],
            ],
        ];
    }
}
