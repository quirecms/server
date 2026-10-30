<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use Quire\Server\Database\SiteDatabases;
use Quire\Server\Docker\Compose;
use Quire\Server\Docker\ComposeFiles;
use Quire\Server\Docker\ComposePolicy;
use Quire\Server\Docker\Images;
use Quire\Server\Docker\RuntimeImages;
use Quire\Server\Docker\SkeletonInstaller;
use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Git\DeployKeys;
use Quire\Server\Git\Git;
use Quire\Server\Git\GitPoller;
use Quire\Server\Git\Repository;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\RuntimeBuilds;
use Quire\Server\Registry\SiteGit;
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
    private const string COMMIT_1 = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
    private const string COMMIT_2 = 'c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2';

    private string $root;
    private SiteName $name;
    private ServerPaths $paths;
    private SiteRegistry $registry;
    private SiteUpdates $updates;
    private OperationLog $operations;
    private ScriptedRunner $runner;
    private SiteGit $repositories;
    private DeployKeys $keys;
    private Git $git;

    /** Git mode: the commit the site's folder has, and the branch's newest in the repository */
    private string $checkedOut = self::COMMIT_1;
    private string $remote = self::COMMIT_2;

    /** @var array<string, string> Image name => ID */
    private array $tags = ['quire-sites/jane/php-prod' => self::OLD];

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

                $this->tags['quire-sites/jane/php-prod'] = self::NEW;

                return ScriptedRunner::ok($command);
            },
            'docker compose up' => function (array $command): ProcessResult {
                if (null !== $this->ups && $this->ups-- <= 0) {
                    return ScriptedRunner::failed($command, "container jane-php-1 is unhealthy\n");
                }

                $this->running = $this->tags['quire-sites/jane/php-prod'];
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
                self::assertStringStartsWith('php:', $command[10]);
                file_put_contents($command[11], 'backup');

                return ScriptedRunner::ok($command);
            },
            'docker image inspect' => fn (array $command): ProcessResult => isset($this->tags[$command[5]]) ? ScriptedRunner::ok($command, $this->tags[$command[5]]."\n") : ScriptedRunner::failed($command, 'No such image'),
            'docker image tag' => function (array $command): ProcessResult {
                $this->tags[$command[4]] = $command[3];

                return ScriptedRunner::ok($command);
            },
            'git ls-remote' => fn (array $command): ProcessResult => ScriptedRunner::ok($command, $this->remote."\trefs/heads/main\n"),
            'git fetch' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'git rev-parse' => fn (array $command): ProcessResult => ScriptedRunner::ok($command, (str_starts_with((string) end($command), 'HEAD') ? $this->checkedOut : $this->remote)."\n"),
            'git merge-base' => static fn (array $command): ProcessResult => \in_array($command[\count($command) - 2], [self::COMMIT_1, self::COMMIT_2], true) ? ScriptedRunner::ok($command) : ScriptedRunner::failed($command, ''),
            'git reset' => function (array $command): ProcessResult {
                $this->checkedOut = (string) end($command);
                // The commit's files: its Dockerfile names it.
                file_put_contents($this->paths->site($this->name).'/Dockerfile', 'FROM '.$this->checkedOut);

                return ScriptedRunner::ok($command);
            },
            'git clean' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'git ls-tree' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
        ]);
        $this->keys = new DeployKeys($this->runner, $this->paths);
        $this->git = new Git($this->runner, $this->keys);
        $this->repositories = new SiteGit($this->connection, $this->schema);
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
        self::assertSame(self::OLD, $this->tags['quire-sites/jane/php-prod:before-update'], 'The image it ran keeps a name: nothing prunes it while the update can be undone.');
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

    public function testRebuildingASiteOnANewerRuntime(): void
    {
        $site = $this->paths->site($this->name);
        $before = [(string) file_get_contents($site.'/composer.lock'), (string) file_get_contents($site.'/Dockerfile')];

        $this->updater()->rebuild($this->name, 'Quire\'s runtime has a newer build', 'agent', 'automatic', static fn (): null => null);

        self::assertSame(self::NEW, $this->running, 'Built again, and started.');
        self::assertSame($before, [(string) file_get_contents($site.'/composer.lock'), (string) file_get_contents($site.'/Dockerfile')], 'Its code as it was.');
        self::assertSame(['docker compose build', 'docker compose cp', 'docker compose up'], array_values(array_filter($this->runner->keys(), static fn (string $key): bool => \in_array($key, ['docker compose build', 'docker compose up', 'docker compose cp'], true))), 'Built beside, backed up, then started.');
        self::assertSame(['site.rebuild', 'succeeded', 'automatic'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status'], $this->operations->latest()[0]['requested_by']]);
        self::assertNull($this->updates->undoable($this->name), 'Not an update to undo.');
    }

    public function testARebuildThatDoesntAnswerIsRolledBack(): void
    {
        $this->healthy = [self::OLD];

        try {
            $this->updater()->rebuild($this->name, 'Quire\'s runtime has a newer build', 'agent', 'automatic', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (UpdateFailed $e) {
            self::assertTrue($e->restored);
        }

        self::assertSame(self::OLD, $this->running, 'The image it had runs again.');
        self::assertSame([], $this->restored, 'No migration ran: its database is kept.');
        self::assertSame(Site::RUNNING, $this->registry->get($this->name)?->status);
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
        self::assertSame(self::OLD, $this->tags['quire-sites/jane/php-prod']);
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
        self::assertSame(['site.undo', 'succeeded'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status']]);
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

    public function testDeployingTheBranchsNewestCommit(): void
    {
        $this->followRepository();

        self::assertSame(self::COMMIT_2, $this->updater()->deploy($this->name, null, 'cli', 'darko', static fn (): null => null));

        self::assertSame([self::COMMIT_2, self::NEW], [$this->checkedOut, $this->running]);
        self::assertSame('FROM '.self::COMMIT_2, file_get_contents($this->paths->site($this->name).'/Dockerfile'));
        self::assertSame(self::COMMIT_2, $this->repositories->of($this->name)['deployed'] ?? null);
        $update = $this->updates->undoable($this->name);
        self::assertSame([SiteUpdates::DEPLOY, self::COMMIT_1, self::COMMIT_2], [$update['kind'] ?? null, $update['commit_before'] ?? null, $update['commit_after'] ?? null]);

        // The folder moves to the commit, it's built while the old commit runs, then swapped.
        $keys = $this->runner->keys();
        self::assertLessThan(array_search('docker compose build', $keys, true), array_search('git reset', $keys, true));
        self::assertLessThan(array_search('docker compose up', $keys, true), array_search('docker compose build', $keys, true));

        // Every Git call fenced, and over SSH with the site's key only.
        foreach ($this->runner->runs as $run) {
            if ('git' === $run['command'][0]) {
                self::assertSame('ssh:https', $run['env']['GIT_ALLOW_PROTOCOL'] ?? null);
                self::assertSame('/dev/null', $run['env']['GIT_CONFIG_GLOBAL'] ?? null);
                self::assertStringContainsString('IdentitiesOnly=yes', (string) ($run['env']['GIT_SSH_COMMAND'] ?? ''));
                self::assertStringContainsString($this->paths->keys($this->name).'/id_ed25519', (string) ($run['env']['GIT_SSH_COMMAND'] ?? ''));
                self::assertContains('core.hooksPath=/dev/null', $run['command']);
                self::assertContains('core.symlinks=false', $run['command']);
            }
        }
    }

    public function testACommitThatDoesntWorkIsRolledBackAndNotTriedAgainByItself(): void
    {
        $this->followRepository();
        $this->healthy = [self::OLD];

        try {
            $this->updater()->deploy($this->name, null, 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (UpdateFailed $e) {
            self::assertTrue($e->restored);
        }

        self::assertSame([self::COMMIT_1, self::OLD], [$this->checkedOut, $this->running], 'The commit and image it had.');
        self::assertSame('FROM '.self::COMMIT_1, file_get_contents($this->paths->site($this->name).'/Dockerfile'));
        $git = $this->repositories->of($this->name);
        self::assertSame([self::COMMIT_1, self::COMMIT_2], [$git['deployed'] ?? null, $git['failed'] ?? null]);
        self::assertSame(Site::RUNNING, $this->registry->get($this->name)?->status);
    }

    public function testACommitItRunsIsntDeployedAgain(): void
    {
        $this->followRepository();
        $this->remote = self::COMMIT_1;

        self::assertNull($this->updater()->deploy($this->name, null, 'cli', 'darko', static fn (): null => null));
        self::assertNotContains('docker compose build', $this->runner->keys());
        self::assertSame([], $this->operations->latest(), 'Nothing to record.');
    }

    public function testOnlyCommitsOfTheBranchAreDeployed(): void
    {
        $this->followRepository();

        try {
            $this->updater()->deploy($this->name, 'dddddddddddddddddddddddddddddddddddddddd', 'cli', 'darko', static fn (): null => null);
            self::fail('A commit off the branch was deployed.');
        } catch (UpdateFailed $e) {
            self::assertStringContainsString('Finding commit dddddddddddd on the branch "main"', $e->getMessage());
        }

        self::assertSame([self::COMMIT_1, self::OLD], [$this->checkedOut, $this->running]);
        self::assertNotContains('docker compose build', $this->runner->keys());

        $this->expectExceptionMessageIsOrContains('isn\'t a commit ID');
        $this->updater()->deploy($this->name, 'HEAD~1', 'cli', 'darko', static fn (): null => null);
    }

    public function testUndoingADeployGoesBackToTheCommitBeforeAndKeepsTheAgentFromRedeployingIt(): void
    {
        $this->followRepository();
        $updater = $this->updater();
        $updater->deploy($this->name, null, 'cli', 'darko', static fn (): null => null);

        $updater->undo($this->name, false, 'cli', 'darko', static fn (): null => null);

        self::assertSame([self::COMMIT_1, self::OLD], [$this->checkedOut, $this->running]);
        $git = $this->repositories->of($this->name);
        self::assertSame([self::COMMIT_1, self::COMMIT_2], [$git['deployed'] ?? null, $git['failed'] ?? null]);

        // The agent asks the repository: its newest commit is the one undone, so nothing happens.
        $this->repositories->setPolling($this->name, true);
        $said = [];
        new GitPoller($this->repositories, $this->git, $updater, 60)->poll(static function (string $line) use (&$said): void {
            $said[] = $line;
        });
        self::assertSame([], $said);
        self::assertSame(self::COMMIT_1, $this->checkedOut);
    }

    public function testTheAgentDeploysNewCommitsOfSitesThatPoll(): void
    {
        $this->followRepository(poll: true);
        $poller = new GitPoller($this->repositories, $this->git, $this->updater(), 60);

        $poller->poll(static fn (): null => null);

        self::assertSame([self::COMMIT_2, self::NEW], [$this->checkedOut, $this->running]);
        $operation = $this->operations->latest()[0];
        self::assertSame(['site.deploy', 'agent', GitPoller::REQUESTER], [$operation['action'], $operation['channel'], $operation['requested_by']]);

        // Asked again only once its interval is over.
        $this->runner->runs = [];
        $poller->poll(static fn (): null => null);
        self::assertSame([], $this->runner->runs);
    }

    public function testASiteInGitModeIsUpdatedInItsRepository(): void
    {
        $this->followRepository();

        $this->expectExceptionMessageIsOrContains('follows a Git repository');
        $this->updater()->update($this->plan(), false, 'cli', 'darko', static fn (): null => null);
    }

    private function followRepository(bool $poll = false): void
    {
        $this->repositories->follow($this->name, new Repository('git@git.example:me/jane.git', 'main'), $poll);
        $this->repositories->deployed($this->name, self::COMMIT_1);
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
        $compose = new Compose($this->runner, new ComposePolicy(), new ComposeFiles(), new RuntimeImages($this->runner), new RuntimeBuilds($this->connection, $this->schema), 'tcp://docker-proxy:2375');
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
            $this->git,
            $this->repositories,
            $this->keys,
            '',
        );

        return new SiteUpdater($this->registry, $this->updates, $this->repositories, $this->operations, new SiteLock($this->connection), $sites, $compose, new Images($this->runner, 'tcp://docker-proxy:2375'), $this->git, $this->paths);
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
                'php' => ['image' => 'quire-sites/jane/php-prod', 'build' => $build, 'cap_drop' => ['ALL'], 'security_opt' => ['no-new-privileges:true'], 'mem_limit' => '536870912', 'pids_limit' => 256, 'networks' => ['default' => null, 'quire-edge' => ['aliases' => ['jane.example']], 'quire-data' => null]],
                'worker' => ['image' => 'quire-sites/jane/php-prod', 'build' => $build, 'cap_drop' => ['ALL'], 'security_opt' => ['no-new-privileges:true'], 'mem_limit' => '536870912', 'pids_limit' => 256, 'networks' => ['default' => null, 'quire-data' => null]],
            ],
        ];
    }
}
