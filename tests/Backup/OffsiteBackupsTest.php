<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use Quire\Server\Backup\BackupFailed;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Backup\Restic;
use Quire\Server\Backup\SiteDatabaseDumps;
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
use Quire\Server\Operation\OperationLog;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\RuntimeBuilds;
use Quire\Server\Registry\RuntimeFloors;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\SiteUpdates;
use Quire\Server\Registry\UpdatePolicies;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Update\SiteUpdater;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Restoring from backups off the server, on the registry's real tables, restic scripted as it answers
 * (its snapshots, by tag and server; a restore writes the files it holds). Mostly what a restore must
 * refuse, since a backup's content is the backup's, not the server's: another site's backup, one that
 * lacks what it needs, a server's list of sites that's wrong.
 */
final class OffsiteBackupsTest extends RegistryTestCase
{
    private const string OLD_SERVER = 'old-server';

    private string $root;
    private ServerPaths $paths;
    private SiteRegistry $registry;
    private ScriptedRunner $runner;

    /** @var list<array{id: string, time: string, hostname: string, tags: list<string>, paths: list<string>}> */
    private array $snapshots = [];

    /** @var array<string, string> What the repository holds: path => content */
    private array $files = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-offsite-'.bin2hex(random_bytes(6));
        $this->paths = new ServerPaths($this->root);
        $this->registry = new SiteRegistry($this->connection, $this->schema);
        $this->runner = new ScriptedRunner([
            'restic --quiet' => fn (array $command): ProcessResult => match ($command[2]) {
                'snapshots' => $this->snapshots($command),
                'restore' => $this->restore($command),
                default => ScriptedRunner::failed($command, 'not scripted'),
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testASnapshotThatIsntOneIsRefusedBeforeAnythingRuns(): void
    {
        try {
            $this->subject()->restore(new SiteName('jane'), '--target=/', 'cli', 'darko', static function (): void {});
            self::fail('It was tried.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('isn\'t a backup', $e->getMessage());
        }

        self::assertSame([], $this->runner->runs);
    }

    public function testAnotherSitesBackupIsntOneOfItsOwn(): void
    {
        $this->snapshot('abcd1234ef', ['quire-site', 'site:blog'], ['/srv/quire/backups/blog/off-server-1.tar']);

        $this->expectExceptionMessageIsOrContains('The site "jane" has no such backup off the server');
        $this->subject()->restore(new SiteName('jane'), 'abcd1234', 'cli', 'darko', static function (): void {});
    }

    public function testAThisSitesSnapshotWithoutItsBackupFileIsRefused(): void
    {
        $this->snapshot('abcd1234ef', ['quire-site', 'site:jane'], ['/srv/quire/sites/jane']);

        $this->expectExceptionMessageIsOrContains('The site "jane" has no such backup off the server');
        $this->subject()->restore(new SiteName('jane'), 'latest', 'cli', 'darko', static function (): void {});
    }

    public function testAGitModeSitesBackupIsntCopiedAsANewSite(): void
    {
        $this->snapshot('abcd1234ef', ['quire-site', 'site:jane', 'git'], ['/srv/quire/backups/jane/off-server-1.tar']);

        $this->expectExceptionMessageIsOrContains('"jane" follows a Git repository: its code is there, not in its backups');
        $this->subject()->restoreAsNew(new SiteName('jane'), 'latest', new SiteName('jane-copy'), [new Domain('copy.example')], null, 'cli', 'darko', static function (): void {});
    }

    public function testABackupWithoutTheSitesFolderCanOnlyGoBackToItsSite(): void
    {
        $this->snapshot('abcd1234ef', ['quire-site', 'site:jane'], ['/srv/quire/backups/jane/off-server-1.tar']);

        $this->expectExceptionMessageIsOrContains('can only be restored to its site');
        $this->subject()->restoreAsNew(new SiteName('jane'), 'latest', new SiteName('jane-copy'), [new Domain('copy.example')], null, 'cli', 'darko', static function (): void {});
    }

    public function testAServersListOfItsSitesKeepsOnlyEntriesItReads(): void
    {
        $this->serverBackup([
            ['name' => 'jane', 'domains' => ['jane.example', 42], 'git' => null, 'updates' => 'security'],
            ['name' => 'kim', 'domains' => ['kim.example'], 'git' => ['url' => 'git@github.com:kim/site.git', 'branch' => 'main', 'poll' => 'yes'], 'updates' => 'off'],
            ['name' => 'odd', 'domains' => ['odd.example'], 'git' => ['url' => 42], 'updates' => 'off'],
            ['name' => 'wrong', 'domains' => ['wrong.example'], 'git' => null, 'updates' => 'everything, always'],
            ['name' => 42, 'domains' => [], 'git' => null, 'updates' => 'off'],
            'not a site',
        ]);

        $manifest = $this->subject()->manifest(self::OLD_SERVER);

        self::assertSame(['jane', 'kim', 'odd'], array_column($manifest['sites'], 'name'));
        self::assertSame(['jane.example'], $manifest['sites'][0]['domains'], 'Only domains that are strings.');
        self::assertSame(['url' => 'git@github.com:kim/site.git', 'branch' => 'main', 'poll' => false], $manifest['sites'][1]['git'], 'Polls only when it says true.');
        self::assertNull($manifest['sites'][2]['git'], 'A repository that isn\'t one is none.');
        new Filesystem()->remove($manifest['into']);
    }

    public function testAServersListThatIsntJsonIsRefusedAndNothingIsLeft(): void
    {
        $this->serverBackup([]);
        $this->files['/srv/quire/backups/server/sites.json'] = '{';

        try {
            $this->subject()->manifest(self::OLD_SERVER);
            self::fail('It was read.');
        } catch (BackupFailed $e) {
            self::assertStringContainsString('isn\'t one this server reads', $e->getMessage());
        }

        self::assertSame([], glob($this->root.'/backups/.restoring-server-*'), 'What was got is removed.');
    }

    public function testAServersBackupFromBeforeTheListSaysSo(): void
    {
        $this->snapshot('5e7e7e7e7e', ['quire-server'], ['/srv/quire/backups/server/registry.dump'], self::OLD_SERVER);

        $this->expectExceptionMessageIsOrContains('has no list of its sites');
        $this->subject()->manifest(self::OLD_SERVER);
    }

    public function testRestoringAServerTakesItsSitesOneByOne(): void
    {
        // jane: a site of this server has its name; "../../etc": a name no site can have; lee: no
        // backup; kim: a Git mode site whose restore fails (nothing more is scripted): its key goes.
        $this->registry->add(new SiteName('jane'), [new Domain('jane.example')], Site::SHARED_DATABASE, 'jane');
        $this->registry->setStatus(new SiteName('jane'), Site::RUNNING);
        $this->serverBackup([
            ['name' => 'jane', 'domains' => ['jane.example'], 'git' => null, 'updates' => 'security'],
            ['name' => '../../etc', 'domains' => ['evil.example'], 'git' => null, 'updates' => 'off'],
            ['name' => 'lee', 'domains' => ['lee.example'], 'git' => null, 'updates' => 'off'],
            ['name' => 'kim', 'domains' => ['kim.example'], 'git' => ['url' => 'git@github.com:kim/site.git', 'branch' => 'main', 'poll' => true], 'updates' => 'off'],
        ]);
        $this->files['/srv/quire/server/keys/kim/id_ed25519'] = 'kim\'s key';
        $this->snapshot('c1c1c1c1c1', ['quire-site', 'site:kim', 'git'], ['/srv/quire/backups/kim/off-server-1.tar'], self::OLD_SERVER);
        $this->files['/srv/quire/backups/kim/off-server-1.tar'] = 'kim\'s data';

        $outcomes = $this->subject()->restoreServer(self::OLD_SERVER, 'cli', 'darko', static function (): void {});

        self::assertSame([['jane', 'skipped'], ['../../etc', 'failed'], ['lee', 'failed'], ['kim', 'failed']], array_map(static fn (array $outcome): array => [$outcome['site'], $outcome['outcome']], $outcomes));
        self::assertSame('a site of this server has its name', $outcomes[0]['detail']);
        self::assertSame('it has no backup', $outcomes[2]['detail']);
        self::assertDirectoryDoesNotExist($this->root.'/server/keys/kim', 'Its deploy key isn\'t left behind.');
        self::assertSame([], glob($this->root.'/backups/.restoring-*'), 'Nothing got is left.');
    }

    private function subject(): OffsiteBackups
    {
        $operations = new OperationLog($this->connection, $this->schema);
        $repositories = new SiteGit($this->connection, $this->schema);
        $keys = new DeployKeys($this->runner, $this->paths);
        $git = new Git($this->runner, $keys);
        $compose = new Compose($this->runner, new ComposePolicy(), new ComposeFiles(), new RuntimeImages($this->runner, new RuntimeFloors($this->connection, $this->schema)), new RuntimeBuilds($this->connection, $this->schema), 'tcp://docker-proxy:2375');
        $sites = new SiteManager($this->registry, $operations, new SiteLock($this->connection, $this->schema), new SkeletonInstaller($this->runner), new SiteDatabases($this->connection), new SiteFiles(), $compose, new DomainDirectory($this->paths->domains()), $this->paths, $git, $repositories, $keys, new SiteDatabaseDumps($this->runner, $compose, 'tcp://docker-proxy:2375'), '');
        $updater = new SiteUpdater($this->registry, new SiteUpdates($this->connection, $this->schema), $repositories, $operations, new SiteLock($this->connection, $this->schema), $sites, $compose, new Images($this->runner, 'tcp://docker-proxy:2375'), $git, $this->paths);
        $settings = new BackupSettings('s3:https://host/bucket', 'the-key', 'the-secret', 'a-long-random-password-0123456789');

        return new OffsiteBackups($settings, new Restic($this->runner, $settings, $this->root.'/backups/.restic-cache'), $this->registry, $sites, new SiteLock($this->connection, $this->schema), $updater, $repositories, new UpdatePolicies($this->connection, $this->schema), $operations, $this->paths, $this->runner, 'tcp://docker-proxy:2375', 'postgresql://postgres:x@quire-database:5432/postgres');
    }

    /**
     * @param list<mixed> $sites
     */
    private function serverBackup(array $sites): void
    {
        $this->snapshot('5e7e7e7e7e', ['quire-server'], ['/srv/quire/backups/server/registry.dump', '/srv/quire/backups/server/sites.json', '/srv/quire/server/keys'], self::OLD_SERVER);
        $this->files['/srv/quire/backups/server/sites.json'] = (string) json_encode(['schema' => 1, 'sites' => $sites]);
    }

    /**
     * @param list<string> $tags
     * @param list<string> $paths
     */
    private function snapshot(string $id, array $tags, array $paths, string $host = 'quire-server'): void
    {
        $this->snapshots[] = ['id' => $id, 'time' => '2026-10-29T02:00:00Z', 'hostname' => $host, 'tags' => $tags, 'paths' => $paths];
    }

    /**
     * @param list<string> $command
     */
    private function snapshots(array $command): ProcessResult
    {
        $at = array_search('--host', $command, true);
        $host = \is_int($at) ? $command[$at + 1] : '';
        $at = array_search('--tag', $command, true);
        $tags = \is_int($at) ? explode(',', $command[$at + 1]) : [];
        $matching = array_values(array_filter($this->snapshots, static fn (array $snapshot): bool => $snapshot['hostname'] === $host && [] === array_diff($tags, $snapshot['tags'])));

        return ScriptedRunner::ok($command, (string) json_encode($matching));
    }

    /**
     * @param list<string> $command
     */
    private function restore(array $command): ProcessResult
    {
        $at = array_search('--target', $command, true);
        $target = \is_int($at) ? $command[$at + 1] : '';

        foreach ($command as $i => $argument) {
            if ('--include' !== $argument) {
                continue;
            }

            $included = $command[$i + 1];

            foreach ($this->files as $path => $content) {
                if ($path === $included || str_starts_with($path, $included.'/')) {
                    new Filesystem()->dumpFile($target.$path, $content);
                }
            }
        }

        return ScriptedRunner::ok($command);
    }
}
