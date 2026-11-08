<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use Quire\Server\Backup\BackupFailed;
use Quire\Server\Backup\BackupLedger;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\BackupSigning;
use Quire\Server\Backup\IncompleteBackup;
use Quire\Server\Backup\LocalBackups;
use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Backup\Provenance;
use Quire\Server\Backup\Restic;
use Quire\Server\Backup\SiteDatabaseDumps;
use Quire\Server\Console\BackupsCommand;
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
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\SiteUpdates;
use Quire\Server\Registry\UpdatePolicies;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\GateFixture;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Update\SiteUpdater;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Restoring from backups off the server, on the registry's real tables, restic scripted as it answers
 * (its snapshots, by tag and server; a restore writes the files it holds). Mostly what a restore must
 * refuse, since a backup's content is the backup's, not the server's: another site's backup, one that
 * lacks what it needs, a server's list of sites that's wrong; and one this server didn't make, or that
 * the server it names didn't sign (D-36).
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

    /** @var array<string, array<string, string>> What a snapshot holds, by its ID, where it differs: path => content */
    private array $held = [];

    /** The size restic gives a restore's files */
    private int $size = 0;

    /** @var list<list<string>> The tags of each site's snapshot sent off the server */
    private array $sent = [];

    private BackupLedger $ledger;
    private BackupSigning $oldServer;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-offsite-'.bin2hex(random_bytes(6));
        $this->paths = new ServerPaths($this->root);
        $this->registry = new SiteRegistry($this->connection, $this->schema);
        $this->ledger = new BackupLedger($this->connection, $this->schema);
        $this->oldServer = new BackupSigning($this->root.'/old-server/backups.key');
        $this->oldServer->ensure();
        $this->runner = new ScriptedRunner([
            ...GateFixture::networkScript(),
            'restic --quiet' => fn (array $command): ProcessResult => match ($command[2]) {
                'snapshots' => $this->snapshots($command),
                'restore' => $this->restore($command),
                'ls' => ScriptedRunner::ok($command, (string) json_encode(['type' => 'file', 'path' => '/x', 'size' => $this->size])),
                'backup' => $this->resticBackup($command),
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
        $this->snapshot($this->id('abcd1234ef'), ['quire-site', 'site:blog'], ['/srv/quire/backups/blog/off-server-1.tar']);

        $this->expectExceptionMessageIsOrContains('The site "jane" has no such backup off the server');
        $this->subject()->restore(new SiteName('jane'), 'abcd1234', 'cli', 'darko', static function (): void {});
    }

    public function testAThisSitesSnapshotWithoutItsBackupFileIsRefused(): void
    {
        $this->snapshot($this->id('abcd1234ef'), ['quire-site', 'site:jane'], ['/srv/quire/sites/jane']);

        $this->expectExceptionMessageIsOrContains('The site "jane" has no such backup off the server');
        $this->subject()->restore(new SiteName('jane'), 'latest', 'cli', 'darko', static function (): void {});
    }

    public function testAGitModeSitesBackupIsntCopiedAsANewSite(): void
    {
        $this->snapshot($this->id('abcd1234ef'), ['quire-site', 'site:jane', 'git'], ['/srv/quire/backups/jane/off-server-1.tar']);

        $this->expectExceptionMessageIsOrContains('"jane" follows a Git repository: its code is there, not in its backups');
        $this->subject()->restoreAsNew(new SiteName('jane'), 'latest', new SiteName('jane-copy'), [new Domain('copy.example')], null, 'cli', 'darko', static function (): void {});
    }

    public function testABackupWithoutTheSitesFolderCanOnlyGoBackToItsSite(): void
    {
        $this->snapshot($this->id('abcd1234ef'), ['quire-site', 'site:jane'], ['/srv/quire/backups/jane/off-server-1.tar']);

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

        $manifest = $this->subject()->manifest(self::OLD_SERVER, $this->oldServer->publicKey());

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
            $this->subject()->manifest(self::OLD_SERVER, $this->oldServer->publicKey());
            self::fail('It was read.');
        } catch (BackupFailed $e) {
            self::assertStringContainsString('isn\'t one this server reads', $e->getMessage());
        }

        self::assertSame([], glob($this->root.'/backups/.restoring-server-*'), 'What was got is removed.');
    }

    public function testAServersBackupFromBeforeTheListSaysSo(): void
    {
        $this->snapshot($this->id('5e7e7e7e7e'), ['quire-server'], ['/srv/quire/backups/server/registry.dump'], self::OLD_SERVER);
        $this->oldServerSigns();

        $this->expectExceptionMessageIsOrContains('has no list of its sites');
        $this->subject()->manifest(self::OLD_SERVER, $this->oldServer->publicKey());
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
        $this->snapshot($this->id('c1c1c1c1c1'), ['quire-site', 'site:kim', 'git'], ['/srv/quire/backups/kim/off-server-1.tar'], self::OLD_SERVER);
        $this->files['/srv/quire/backups/kim/off-server-1.tar'] = 'kim\'s data';
        $this->oldServerSigns();

        $outcomes = $this->subject()->restoreServer(self::OLD_SERVER, 'cli', 'darko', static function (): void {}, $this->oldServer->publicKey());

        self::assertSame([['jane', 'skipped'], ['../../etc', 'failed'], ['lee', 'failed'], ['kim', 'failed']], array_map(static fn (array $outcome): array => [$outcome['site'], $outcome['outcome']], $outcomes));
        self::assertSame('a site of this server has its name', $outcomes[0]['detail']);
        self::assertSame('it has no backup', $outcomes[2]['detail']);
        self::assertDirectoryDoesNotExist($this->root.'/server/keys/kim', 'Its deploy key isn\'t left behind.');
        self::assertSame([], glob($this->root.'/backups/.restoring-*'), 'Nothing got is left.');
    }

    public function testAPlantedSnapshotIsntRestoredAsTheSitesOwn(): void
    {
        // The audit's ISO-08: whoever has the backups' password puts alice's data in a snapshot tagged jane's
        $this->snapshot($this->id('beef'), ['quire-site', 'site:jane'], ['/srv/quire/backups/jane/off-server-1.tar'], made: false);

        try {
            $this->subject()->restore(new SiteName('jane'), 'latest', 'cli', 'darko', static function (): void {});
            self::fail('It was restored.');
        } catch (BackupFailed $e) {
            self::assertStringContainsString('The backup beef0000 of "jane" isn\'t one this server made', $e->getMessage());
            self::assertStringContainsString('--unsigned', $e->getMessage());
        }

        $said = [];

        try {
            $this->subject()->restore(new SiteName('jane'), 'latest', 'cli', 'darko', static function (string $line) use (&$said): void {
                $said[] = $line;
            }, unsigned: true);
            self::fail('There was nothing to restore.');
        } catch (BackupFailed $e) {
            self::assertSame('The snapshot doesn\'t have its backup file.', $e->getMessage(), 'Past the check: the owner asked.');
        }

        self::assertSame('Not verified (--unsigned): the backup beef0000 of "jane", as you asked', $said[0]);
    }

    public function testTheLatestIsTheNewestThisServerMade(): void
    {
        $this->snapshot($this->id('01d'), ['quire-server'], ['/srv/quire/backups/server/sites.json'], time: '2026-10-28T02:00:00Z');
        $this->held[$this->id('01d')]['/srv/quire/backups/server/sites.json'] = (string) json_encode(['schema' => 1, 'sites' => [['name' => 'jane', 'domains' => ['jane.example'], 'git' => null, 'updates' => 'security']]]);
        $this->snapshot($this->id('9e7'), ['quire-server'], ['/srv/quire/backups/server/sites.json'], made: false, time: '2026-10-30T02:00:00Z');
        $this->held[$this->id('9e7')]['/srv/quire/backups/server/sites.json'] = (string) json_encode(['schema' => 1, 'sites' => [['name' => 'evil', 'domains' => ['evil.example'], 'git' => ['url' => 'git@evil.example:x.git', 'branch' => 'main', 'poll' => true], 'updates' => 'off']]]);

        $manifest = $this->subject()->manifest('quire-server');
        new Filesystem()->remove($manifest['into']);

        self::assertSame(['jane'], array_column($manifest['sites'], 'name'), 'Not the newer one planted beside it.');
    }

    public function testAnotherServersBackupsNeedItsKeyThenItIsTrusted(): void
    {
        $this->serverBackup([['name' => 'jane', 'domains' => ['jane.example'], 'git' => null, 'updates' => 'security']]);

        foreach ([[null, 'isn\'t one "old-server" signed with a key this server trusts'], [new BackupSigning($this->root.'/someone/backups.key'), 'isn\'t signed by its key']] as [$other, $expected]) {
            $key = null;

            if ($other instanceof BackupSigning) {
                $other->ensure();
                $key = $other->publicKey();
            }

            try {
                $this->subject()->manifest(self::OLD_SERVER, $key);
                self::fail('It was read.');
            } catch (BackupFailed $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }

        new Filesystem()->remove($this->subject()->manifest(self::OLD_SERVER, $this->oldServer->publicKey())['into']);
        self::assertSame([$this->oldServer->publicKey()], $this->ledger->trusted(self::OLD_SERVER));
        new Filesystem()->remove($this->subject()->manifest(self::OLD_SERVER)['into']);
    }

    public function testAListAnotherKeySignedDoesntVouchNorHideTheRealOne(): void
    {
        $this->serverBackup([['name' => 'jane', 'domains' => ['jane.example'], 'git' => null, 'updates' => 'security']]);
        $someone = new BackupSigning($this->root.'/someone/backups.key');
        $someone->ensure();
        // A newer list, of everything, signed by someone else's key
        $this->oldServerSigns($someone);

        new Filesystem()->remove($this->subject()->manifest(self::OLD_SERVER, $this->oldServer->publicKey())['into']);
        // With the key it trusts now, too
        new Filesystem()->remove($this->subject()->manifest(self::OLD_SERVER)['into']);
        self::assertSame([$this->oldServer->publicKey()], $this->ledger->trusted(self::OLD_SERVER), 'Not someone\'s.');
    }

    public function testAKeyThatIsntOneIsRefused(): void
    {
        $this->serverBackup([]);

        $this->expectExceptionMessageIsOrContains('isn\'t a public key as servers show them');
        $this->subject()->manifest(self::OLD_SERVER, 'ed25519:not-a-key');
    }

    public function testAnIncompleteBackupGoesOffTheServerTaggedAndFails(): void
    {
        $this->runningSite(3);

        try {
            $this->subject()->backUpNow(new SiteName('jane'), 'cli', 'darko', static function (): void {});
            self::fail('An incomplete backup passed for a good one.');
        } catch (IncompleteBackup $e) {
            self::assertStringContainsString('some stored files aren\'t in it, and it went off the server as 5174e000, tagged "incomplete".', $e->getMessage());
            self::assertStringContainsString('media:ab/cd.jpg', $e->getMessage(), 'Which files.');
            self::assertStringNotContainsString('It\'s kept at', $e->getMessage(), 'Its copy here is gone: it\'s off the server.');
        }

        // Sent all the same, as the best copy there is, and kept apart by retention (grouped by tags)
        self::assertSame([['quire-site', 'site:jane', 'incomplete']], $this->sent);
        $left = glob($this->paths->backups(new SiteName('jane')).'/off-server-*');
        self::assertSame([], false === $left ? [] : $left, 'Nothing of it left here: not even its mark.');
        self::assertSame(['site.backup', 'failed'], [$this->operations()->latest()[0]['action'], $this->operations()->latest()[0]['status']]);
    }

    public function testACompleteBackupIsntTagged(): void
    {
        $this->runningSite(0);

        $this->subject()->backUpNow(new SiteName('jane'), 'cli', 'darko', static function (): void {});

        self::assertSame([['quire-site', 'site:jane']], $this->sent);
    }

    public function testABackupThatFailedIsntSent(): void
    {
        $this->runningSite(1);

        try {
            $this->subject()->backUpNow(new SiteName('jane'), 'cli', 'darko', static function (): void {});
            self::fail('It passed.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(IncompleteBackup::class, $e);
            self::assertStringContainsString('Backing up the site failed', $e->getMessage());
        }

        self::assertSame([], $this->sent, 'No backup, nothing sent.');
    }

    public function testRetentionKeepsIncompleteSnapshotsApart(): void
    {
        $forget = [];
        $this->runner = new ScriptedRunner([
            'restic --quiet' => static function (array $command) use (&$forget): ProcessResult {
                $forget = $command;

                return ScriptedRunner::ok($command, '[]');
            },
        ]);
        $settings = new BackupSettings('s3:https://host/bucket', 'the-key', 'the-secret', 'a-long-random-password-0123456789');

        new Restic($this->runner, $settings, $this->root.'/backups/.restic-cache')->forget(prune: false);

        // Each set of tags is its own group: a week of incomplete nights leaves the complete ones as they were
        $group = array_search('--group-by', $forget, true);
        self::assertIsInt($group);
        self::assertSame('host,tags', $forget[$group + 1]);
    }

    public function testTheListsMarkIncompleteBackups(): void
    {
        $this->snapshot($this->id('abcd1234ef'), ['quire-site', 'site:jane'], ['/srv/quire/backups/jane/off-server-1.tar'], time: '2026-10-28T02:00:00Z');
        $this->snapshot($this->id('bcde2345fa'), ['quire-site', 'site:jane', 'incomplete'], ['/srv/quire/backups/jane/off-server-2.tar'], time: '2026-10-29T02:00:00Z');
        new Filesystem()->dumpFile($this->paths->backups(new SiteName('jane')).'/update-20261029-120000.tar', 'x');
        new Filesystem()->dumpFile($this->paths->backups(new SiteName('jane')).'/update-20261029-120000.incomplete', 'media:ab/cd.jpg');
        new Filesystem()->dumpFile($this->paths->backups(new SiteName('jane')).'/update-20261028-120000.tar', 'x');
        $application = new Application();
        $application->addCommand(new BackupsCommand($this->subject(), new LocalBackups(new OperationLog($this->connection, $this->schema), $this->paths->root.'/backups')));
        $tester = new CommandTester($application->find('server:backups'));

        $tester->execute(['name' => 'jane']);

        $rows = array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $line): bool => 1 === preg_match('/^\s*(abcd1234|bcde2345)/', $line)));
        self::assertCount(2, $rows);
        self::assertStringContainsString('INCOMPLETE: some stored files aren\'t in it', $rows[0], 'The newest, which lacks some.');
        self::assertStringStartsWith('bcde2345', trim($rows[0]));
        self::assertStringNotContainsString('INCOMPLETE', $rows[1]);

        $tester->execute(['name' => 'jane', '--local' => true]);

        $rows = array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $line): bool => str_contains($line, '.tar')));
        self::assertCount(2, $rows);
        self::assertStringContainsString('update-20261029-120000.tar', $rows[0]);
        self::assertStringContainsString('INCOMPLETE: some stored files aren\'t in it', $rows[0]);
        self::assertStringNotContainsString('INCOMPLETE', $rows[1]);
    }

    public function testARestoreThatWouldFillTheDiskIsRefused(): void
    {
        $this->snapshot($this->id('abcd1234ef'), ['quire-site', 'site:jane'], ['/srv/quire/backups/jane/off-server-1.tar']);
        $this->size = \PHP_INT_MAX >> 2;

        $this->expectExceptionMessageIsOrContains('make room first');
        $this->subject()->restore(new SiteName('jane'), 'latest', 'cli', 'darko', static function (): void {});
    }

    /**
     * The site "jane", running with a database of its own; quire:backup exits with the code given (3:
     * written, without some stored files).
     */
    private function runningSite(int $backupExit): void
    {
        $this->registry->add(new SiteName('jane'), [new Domain('jane.example')], Site::OWN_DATABASE, 'jane');
        new SiteNetworks($this->connection, $this->schema)->allocate(new SiteName('jane'), new \Quire\Server\Network\SiteNetworkRange(\Quire\Server\Network\SiteNetworkRange::DEFAULT), []);
        $this->registry->setStatus(new SiteName('jane'), Site::RUNNING);
        new Filesystem()->dumpFile($this->paths->site(new SiteName('jane')).'/'.SiteFiles::ENV_FILE, "COMPOSE_PROJECT_NAME='jane'\n");
        $this->runner = new ScriptedRunner([
            ...$this->runner->script,
            'docker compose exec' => static fn (array $command): ProcessResult => 'quire:backup' === ($command[12] ?? '')
                ? new ProcessResult($command, $backupExit, 3 === $backupExit ? " [WARNING] 1 stored file(s) were missing, so the backup has no good copy of them:\n           media:ab/cd.jpg\n" : '', 1 === $backupExit ? 'No space left on device' : '')
                : ScriptedRunner::ok($command),
            'docker compose cp' => static function (array $command): ProcessResult {
                file_put_contents((string) end($command), 'backup');

                return ScriptedRunner::ok($command);
            },
        ]);
    }

    /**
     * restic's backup, as it says what it made: the tags of each site's, as sent.
     *
     * @param list<string> $command
     */
    private function resticBackup(array $command): ProcessResult
    {
        $tags = [];

        foreach ($command as $i => $argument) {
            if ('--tag' === $argument) {
                $tags[] = $command[$i + 1];
            }
        }

        if (\in_array(OffsiteBackups::SITE_TAG, $tags, true)) {
            $this->sent[] = $tags;
        }

        return ScriptedRunner::ok($command, (string) json_encode(['message_type' => 'summary', 'snapshot_id' => $this->id('5174e000aa')]));
    }

    private function operations(): OperationLog
    {
        return new OperationLog($this->connection, $this->schema);
    }

    private function subject(): OffsiteBackups
    {
        $operations = new OperationLog($this->connection, $this->schema);
        $repositories = new SiteGit($this->connection, $this->schema);
        $keys = new DeployKeys($this->runner, $this->paths);
        $git = new Git($this->runner, $keys, new \Quire\Server\Git\GitHosts(resolve: static fn (): array => ['140.82.121.4']));
        $compose = new Compose($this->runner, new ComposePolicy(), new ComposeFiles(), new RuntimeImages($this->runner, new RuntimeFloors($this->connection, $this->schema)), new RuntimeBuilds($this->connection, $this->schema), GateFixture::gate($this->runner, $this->root, 'jane', 'jane-copy', 'old-site'), GateFixture::builders($this->runner, $this->root));
        $sites = new SiteManager($this->registry, $operations, new SiteLock($this->connection, $this->schema), new SkeletonInstaller($this->runner), new SiteDatabases($this->connection), new SiteFiles(), $compose, new DomainDirectory($this->paths->domains()), $this->paths, $git, $repositories, $keys, new SiteDatabaseDumps($this->runner, $compose, GateFixture::TRUSTED), GateFixture::links($this->runner, $this->connection, $this->schema), new SiteNetworks($this->connection, $this->schema), '');
        $updater = new SiteUpdater($this->registry, new SiteUpdates($this->connection, $this->schema), $repositories, $operations, new SiteLock($this->connection, $this->schema), $sites, $compose, new Images($this->runner, GateFixture::gate($this->runner, $this->root, 'jane', 'jane-copy', 'old-site')), $git, $this->paths);
        $settings = new BackupSettings('s3:https://host/bucket', 'the-key', 'the-secret', 'a-long-random-password-0123456789');

        return new OffsiteBackups($settings, new Restic($this->runner, $settings, $this->root.'/backups/.restic-cache'), $this->registry, $sites, new SiteLock($this->connection, $this->schema), $updater, $repositories, new UpdatePolicies($this->connection, $this->schema), $operations, $this->paths, $this->runner, GateFixture::TRUSTED, 'postgresql://postgres:x@quire-database:5432/postgres', $this->ledger, new BackupSigning($this->root.'/server/signing/backups.key'), new \Quire\Server\Backup\BackupWatch($settings, new Restic($this->runner, $settings, $this->root.'/backups/.restic-cache'), $this->ledger, new \Quire\Server\Notify\Checks($this->connection, $this->schema), new FakeBucket()->client()));
    }

    /**
     * @param list<mixed> $sites
     */
    private function serverBackup(array $sites): void
    {
        $this->snapshot($this->id('5e7e7e7e7e'), ['quire-server'], ['/srv/quire/backups/server/registry.dump', '/srv/quire/backups/server/sites.json', '/srv/quire/server/keys'], self::OLD_SERVER);
        $this->files['/srv/quire/backups/server/sites.json'] = (string) json_encode(['schema' => 1, 'sites' => $sites]);
        $this->oldServerSigns();
    }

    /**
     * @param list<string> $tags
     * @param list<string> $paths
     */
    private function snapshot(string $id, array $tags, array $paths, string $host = 'quire-server', bool $made = true, string $time = '2026-10-29T02:00:00Z'): void
    {
        $this->snapshots[] = ['id' => $id, 'time' => $time, 'hostname' => $host, 'tags' => $tags, 'paths' => $paths];
        $site = array_find($tags, static fn (string $tag): bool => str_starts_with($tag, 'site:'));

        // This server's own: in its registry, as it records what it makes
        if ($made && 'quire-server' === $host) {
            $this->ledger->record('s3:https://host/bucket', $id, $host, null === $site ? BackupLedger::SERVER : BackupLedger::SITE, null === $site ? null : substr($site, 5), new \DateTimeImmutable($time));
        }
    }

    /**
     * The old server's signed list of its snapshots, as it backs it up after each night's: all of its
     * snapshots so far, or those given.
     *
     * @param list<string>|null $only
     */
    private function oldServerSigns(?BackupSigning $signing = null, ?array $only = null): void
    {
        $records = [];

        foreach ($this->snapshots as $snapshot) {
            if (self::OLD_SERVER !== $snapshot['hostname'] || \in_array(Provenance::TAG, $snapshot['tags'], true) || (null !== $only && !\in_array($snapshot['id'], $only, true))) {
                continue;
            }

            $site = array_find($snapshot['tags'], static fn (string $tag): bool => str_starts_with($tag, 'site:'));
            $records[$snapshot['id']] = ['id' => $snapshot['id'], 'host' => self::OLD_SERVER, 'kind' => null === $site ? BackupLedger::SERVER : BackupLedger::SITE, 'site' => null === $site ? null : substr($site, 5), 'created_at' => $snapshot['time']];
        }

        $id = $this->id(bin2hex(random_bytes(4)));
        $this->snapshot($id, [Provenance::TAG], ['/srv/quire/backups/server/'.Provenance::FILE], self::OLD_SERVER, time: '2026-10-29T03:00:00Z');
        $this->held[$id]['/srv/quire/backups/server/'.Provenance::FILE] = Provenance::document(self::OLD_SERVER, $records, $signing ?? $this->oldServer, new \DateTimeImmutable());
    }

    /**
     * A snapshot's ID as restic makes them (64 hexadecimal digits), from a recognisable start.
     */
    private function id(string $start): string
    {
        return str_pad($start, 64, '0');
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
        $files = [...$this->files, ...($this->held[$command[3]] ?? [])];

        foreach ($command as $i => $argument) {
            if ('--include' !== $argument) {
                continue;
            }

            $included = $command[$i + 1];

            foreach ($files as $path => $content) {
                if ($path === $included || str_starts_with($path, $included.'/')) {
                    new Filesystem()->dumpFile($target.$path, $content);
                }
            }
        }

        return ScriptedRunner::ok($command);
    }
}
