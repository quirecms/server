<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Operation;

use Quire\Server\Database\SiteDatabases;
use Quire\Server\Docker\Compose;
use Quire\Server\Docker\ComposePolicy;
use Quire\Server\Docker\PolicyViolation;
use Quire\Server\Docker\RuntimeImages;
use Quire\Server\Docker\SkeletonInstaller;
use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Git\DeployKeys;
use Quire\Server\Git\Git;
use Quire\Server\Git\Repository;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Operation\SiteRequest;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;
use Quire\Server\Registry\RuntimeBuilds;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Adding and removing sites, with Docker and Composer scripted (what they'd print), and the real
 * registry, PostgreSQL, files and front door folder: above all, what's undone when a step fails.
 */
final class SiteManagerTest extends RegistryTestCase
{
    private string $root;
    private SiteName $name;
    private ServerPaths $paths;
    private SiteRegistry $registry;
    private OperationLog $operations;
    private SiteDatabases $databases;
    private DomainDirectory $frontDoor;

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var array<string, \Closure(list<string>): ProcessResult> What a command prints, by its first words */
    private array $script = [];

    /** Whether the site's configuration has its own PostgreSQL (an adopted site's) */
    private bool $ownDatabase = false;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-server-'.bin2hex(random_bytes(6));
        $this->paths = new ServerPaths($this->root);
        $this->name = new SiteName('t'.bin2hex(random_bytes(3)).'-jane');
        $this->registry = new SiteRegistry($this->connection, $this->schema);
        $this->operations = new OperationLog($this->connection, $this->schema);
        $this->databases = new SiteDatabases($this->connection);
        $this->frontDoor = new DomainDirectory($this->paths->domains());

        $this->script = [
            'composer create-project' => static function (array $command): ProcessResult {
                mkdir(self::argument($command, 3), 0o755, true);
                file_put_contents(self::argument($command, 3).'/compose.yaml', "services: {}\n");

                return self::ok($command, "Installing quire/skeleton (v0.1.1)\n");
            },
            'docker compose config' => fn (array $command): ProcessResult => self::ok($command, json_encode($this->config(), \JSON_THROW_ON_ERROR)),
            'docker compose build' => static fn (array $command): ProcessResult => self::ok($command, "Built\n"),
            'docker compose up' => static fn (array $command): ProcessResult => self::ok($command, "Started\n"),
            'docker compose exec' => static fn (array $command): ProcessResult => match ($command[12] ?? '') {
                'quire:about' => self::ok($command, '{"contract": 1}'),
                'quire:install' => self::ok($command, "Quire is installed.\n [WARNING] The admin's password is: Sup3rSecretAdminPw\n"),
                'quire:backup' => self::ok($command, "Backed up.\n"),
                default => self::ok($command, ''),
            },
            'docker compose cp' => static function (array $command): ProcessResult {
                file_put_contents(self::argument($command, 10), 'backup');

                return self::ok($command, '');
            },
            'docker compose down' => static fn (array $command): ProcessResult => self::ok($command, "Removed\n"),
        ];
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->databases->drop($this->name);
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testAddingASite(): void
    {
        $said = [];
        $added = $this->manager()->add($this->request(), 'cli', 'darko', static function (string $step) use (&$said): void {
            $said[] = $step;
        });

        self::assertSame(Site::RUNNING, $added->site->status);
        self::assertSame('Sup3rSecretAdminPw', $added->adminPassword, 'Shown once to whoever asked.');
        self::assertSame(['Recording the site', 'Getting Quire ^0.1', 'Creating its database', 'Writing its settings', 'Checking and building it (a few minutes)', 'Starting it', 'Installing it', 'Opening it to visitors'], $said);
        self::assertSame([$this->name->value, $this->name->value], array_values($this->frontDoor->all()), 'Both domains open.');
        self::assertTrue($this->databases->exists($this->name));

        // Composer never runs the skeleton's code; the policy is checked before building and starting.
        self::assertSame(['composer', 'create-project', 'quire/skeleton', $this->paths->site($this->name), '^0.1', '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--no-ansi'], $this->commands[0]);
        self::assertSame(['config', 'build', 'config', 'up', 'exec', 'exec', 'cp'], array_map(static fn (array $command): string => $command[8], \array_slice($this->commands, 1)));
        self::assertFileExists($this->paths->site($this->name).'/composer.lock', 'The versions its image was built with, recorded in its folder.');

        $env = (string) file_get_contents($this->paths->site($this->name).'/prod.env');
        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->paths->site($this->name).'/prod.env')), -4));
        self::assertMatchesRegularExpression("/^QUIRE_DATABASE_PASSWORD='[0-9a-f]{48}'\$/m", $env);
        self::assertMatchesRegularExpression("/^APP_SECRET='[0-9a-f]{64}'\$/m", $env);
        $override = (string) file_get_contents($this->paths->site($this->name).'/compose.server.yaml');
        self::assertStringContainsString('${QUIRE_DATABASE_PASSWORD}', $override, 'It names the variable…');
        self::assertStringNotContainsString($this->envValue($env, 'QUIRE_DATABASE_PASSWORD'), $override, '…never the secret: the override holds none.');
        self::assertStringNotContainsString($this->envValue($env, 'APP_SECRET'), $override);

        $operation = $this->operations->latest()[0];
        self::assertSame(['site.add', 'succeeded', 'darko'], [$operation['action'], $operation['status'], $operation['requested_by']]);
        $log = implode("\n", $this->operations->lines($operation['id']));
        self::assertStringNotContainsString('Sup3rSecretAdminPw', $log, 'The admin\'s password never reaches the log.');
        self::assertStringContainsString('[secret]', $log);
        self::assertStringNotContainsString($this->envValue($env, 'QUIRE_DATABASE_PASSWORD'), $log);
    }

    public function testAFailedBuildUndoesEverything(): void
    {
        $this->script['docker compose build'] = static fn (array $command): ProcessResult => new ProcessResult(self::strings($command), 1, '', "no space left on device\n");

        try {
            $this->manager()->add($this->request(), 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no space left on device', $e->getMessage());
        }

        $this->assertNothingLeft();
        self::assertContains('down', array_map(static fn (array $command): string => $command[8] ?? '', $this->commands), 'Its containers and volumes removed.');
        self::assertSame('failed', $this->operations->latest()[0]['status']);

        // Its name and domains are free to try again.
        unset($this->script['docker compose build']);
        $this->script['docker compose build'] = static fn (array $command): ProcessResult => self::ok($command, '');
        self::assertSame(Site::RUNNING, $this->manager()->add($this->request(), 'cli', 'darko', static fn (): null => null)->site->status);
    }

    public function testRestoringABackupAsANewSite(): void
    {
        [$code, $backup] = $this->backedUpSite();
        $said = [];
        $site = $this->manager()->restoreAsNew($this->name, $this->request()->domains, $code, $backup, ['snapshot' => '1a2b3c4d', 'from_site' => 'jane'], 'cli', 'darko', static function (string $step) use (&$said): void {
            $said[] = $step;
        });

        self::assertSame(Site::RUNNING, $site->status);
        self::assertSame(['Recording the site', 'Putting its code in place, as the backup has it', 'Creating its database', 'Writing its settings', 'Checking and building it (a few minutes)', 'Starting it', 'Restoring its database and files from the backup', 'Opening it to visitors'], $said);
        $directory = $this->paths->site($this->name);
        self::assertSame('<?php // the site\'s own code', file_get_contents($directory.'/src/Kernel.php'), 'Its code, as backed up.');
        self::assertFileDoesNotExist($directory.'/prod.env.before-server');

        // Its settings are its own: none of the backed-up site's secrets.
        $env = (string) file_get_contents($directory.'/prod.env');
        self::assertStringNotContainsString('the-old-secret', $env);
        self::assertStringNotContainsString('the-old-password', $env);
        self::assertMatchesRegularExpression("/^APP_SECRET='[0-9a-f]{64}'\$/m", $env);
        self::assertStringNotContainsString('old-site', (string) file_get_contents($directory.'/compose.server.yaml'));
        self::assertTrue($this->databases->exists($this->name));

        // Its data restored, streamed in; never installed afresh.
        $consoles = array_map(static fn (array $command): string => $command[12] ?? '', array_filter($this->commands, static fn (array $command): bool => 'exec' === ($command[8] ?? null)));
        self::assertContains('quire:restore', $consoles);
        self::assertNotContains('quire:install', $consoles);
        self::assertSame([$this->name->value, $this->name->value], array_values($this->frontDoor->all()));
        self::assertSame(['site.restore_as_new', 'succeeded'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status']]);
    }

    public function testARestoreAsANewSiteThatFailsUndoesEverything(): void
    {
        [$code, $backup] = $this->backedUpSite();
        $this->script['docker compose exec'] = static fn (array $command): ProcessResult => 'quire:restore' === ($command[12] ?? '')
            ? new ProcessResult(self::strings($command), 1, '', "The backup is of another version of Quire.\n")
            : self::ok($command, '');

        try {
            $this->manager()->restoreAsNew($this->name, $this->request()->domains, $code, $backup, [], 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('another version of Quire', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->paths->site($this->name).'/compose.yaml', 'Its folder moved away.');
        self::assertFalse($this->databases->exists($this->name));
        self::assertSame([], $this->frontDoor->all());
        self::assertSame(Site::REMOVED, $this->registry->get($this->name)?->status);
        self::assertSame('failed', $this->operations->latest()[0]['status']);
    }

    public function testRestoringAGitModeSiteTakesItsCodeFromItsRepository(): void
    {
        [, $backup] = $this->backedUpSite();
        $commit = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
        $this->script['git -c'] = static function (array $command): ProcessResult {
            // git clone: the branch's files, in the folder named last
            new Filesystem()->dumpFile(self::argument($command, \count($command) - 1).'/.git/info/exclude', '');

            return self::ok($command, '');
        };
        $this->script['git -C'] = static fn (array $command): ProcessResult => self::ok($command, $commit."\n");

        $site = $this->manager()->restoreAsNew($this->name, $this->request()->domains, new Repository('https://example.com/jane.git', 'main'), $backup, [], 'cli', 'darko', static fn (): null => null, poll: true);

        self::assertSame(Site::RUNNING, $site->status);
        $git = new SiteGit($this->connection, $this->schema)->of($this->name);
        self::assertSame(['https://example.com/jane.git', 'main', $commit, true], [$git['repository']->url ?? null, $git['repository']->branch ?? null, $git['deployed'] ?? null, $git['poll'] ?? null]);
        self::assertContains('quire:restore', array_map(static fn (array $command): string => $command[12] ?? '', $this->commands));
    }

    public function testOnlyASitesFolderIsRestoredAsOne(): void
    {
        [$code, $backup] = $this->backedUpSite();
        unlink($code.'/compose.prod.yaml');
        $this->expectExceptionMessageIsOrContains('isn\'t a Quire site\'s');
        $this->manager()->restoreAsNew($this->name, $this->request()->domains, $code, $backup, [], 'cli', 'darko', static fn (): null => null);
    }

    public function testAHostileConfigurationIsNeverBuilt(): void
    {
        $this->script['docker compose config'] = fn (array $command): ProcessResult => self::ok($command, json_encode(array_replace_recursive($this->config(), ['services' => ['php' => ['privileged' => true]]]), \JSON_THROW_ON_ERROR));

        try {
            $this->manager()->add($this->request(), 'cli', 'darko', static fn (): null => null);
            self::fail('It wasn\'t refused.');
        } catch (PolicyViolation $e) {
            self::assertStringContainsString('"privileged" runs privileged', $e->getMessage());
        }

        self::assertNotContains('build', array_map(static fn (array $command): string => $command[8] ?? '', $this->commands));
        self::assertNotContains('up', array_map(static fn (array $command): string => $command[8] ?? '', $this->commands));
        $this->assertNothingLeft();
    }

    public function testAnotherSitesDomainStaysItsOwn(): void
    {
        $this->registry->add(new SiteName('kim'), [new Domain('www.jane.example')], Site::SHARED_DATABASE, 'kim');
        $this->registry->setStatus(new SiteName('kim'), Site::RUNNING);
        $this->frontDoor->allow(new Domain('www.jane.example'), new SiteName('kim'));

        try {
            $this->manager()->add($this->request(), 'cli', 'darko', static fn (): null => null);
            self::fail('A domain was taken twice.');
        } catch (\DomainException $e) {
            self::assertSame('The domain "www.jane.example" belongs to the site "kim".', $e->getMessage());
        }

        self::assertSame(['www.jane.example' => 'kim'], $this->frontDoor->all(), 'Kim\'s domain is still open, for kim.');
        self::assertSame([], $this->commands, 'Nothing was run.');
        self::assertFileDoesNotExist($this->paths->site($this->name));
    }

    public function testAFolderThatWasThereIsLeftAlone(): void
    {
        mkdir($this->paths->site($this->name), 0o755, true);
        file_put_contents($this->paths->site($this->name).'/precious.txt', 'not ours');

        try {
            $this->manager()->add($this->request(), 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t refuse.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('exists already', $e->getMessage());
        }

        self::assertSame('not ours', file_get_contents($this->paths->site($this->name).'/precious.txt'), 'Never moved or deleted.');
        self::assertNull($this->registry->get($this->name));
    }

    public function testRemovingASiteBacksItUpFirstAndKeepsItsFolder(): void
    {
        $manager = $this->manager();
        $manager->add($this->request(), 'cli', 'darko', static fn (): null => null);
        $this->commands = [];

        $backup = $manager->remove($this->name, true, 'cli', 'darko', static fn (): null => null);

        self::assertNotNull($backup);
        self::assertStringStartsWith($this->paths->backups($this->name).'/final-', $backup);
        self::assertSame('0600', substr(\sprintf('%o', fileperms($backup)), -4));
        $commands = $this->commands();
        self::assertSame(['exec', 'cp', 'exec', 'down'], array_map(static fn (array $command): string => $command[8] ?? '', $commands), 'Backed up before anything is stopped.');
        self::assertContains('--volumes', $commands[3] ?? []);
        self::assertSame([], $this->frontDoor->all());
        self::assertFalse($this->databases->exists($this->name));
        self::assertFileDoesNotExist($this->paths->site($this->name));
        $archived = glob($this->paths->sites().'/.removed/'.$this->name->value.'-*');
        self::assertCount(1, false === $archived ? [] : $archived, 'Its folder is archived, not deleted.');
        self::assertSame(Site::REMOVED, $this->registry->get($this->name)?->status);
    }

    public function testASiteThatCantBeBackedUpIsntRemoved(): void
    {
        $manager = $this->manager();
        $manager->add($this->request(), 'cli', 'darko', static fn (): null => null);
        $this->script['docker compose exec'] = static fn (array $command): ProcessResult => new ProcessResult(self::strings($command), 1, '', 'service "php" is not running');

        try {
            $manager->remove($this->name, true, 'cli', 'darko', static fn (): null => null);
            self::fail('It was removed without a backup.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Backing up the site failed', $e->getMessage());
        }

        self::assertSame(Site::RUNNING, $this->registry->get($this->name)?->status);
        self::assertCount(2, $this->frontDoor->all(), 'Still open.');
        self::assertTrue($this->databases->exists($this->name));
        self::assertFileExists($this->paths->site($this->name).'/prod.env');
    }

    public function testAdoptingASiteThatRunsAlready(): void
    {
        $original = $this->siteInstalledByHand();
        $said = [];

        $site = $this->manager()->adopt($this->name, [], 'cli', 'darko', static function (string $step) use (&$said): void {
            $said[] = $step;
        });

        self::assertSame([Site::RUNNING, Site::OWN_DATABASE, $this->name->value], [$site->status, $site->databaseMode, $site->project]);
        self::assertSame(['jane.example', 'www.jane.example'], array_map(static fn (Domain $domain): string => $domain->value, $site->domains), 'From its SERVER_NAME.');
        self::assertSame(['Recording the site', 'Backing it up, as it is', 'Changing its settings (a copy of them is kept)', 'Checking and building it (a few minutes; it keeps running meanwhile)', 'Moving it behind the front door (a few seconds offline)', 'Opening it to visitors'], $said);
        // Backed up before anything changes; built before it's stopped; the policy checked before both.
        self::assertSame(['exec', 'cp', 'exec', 'config', 'build', 'config', 'up', 'cp'], array_map(static fn (array $command): string => $command[8] ?? '', $this->commands));
        self::assertFileExists($this->paths->site($this->name).'/composer.lock', 'Its versions recorded.');
        $backups = glob($this->paths->backups($this->name).'/before-server-*.tar');
        self::assertCount(1, false === $backups ? [] : $backups, 'Backed up as it was.');
        self::assertSame([$this->name->value, $this->name->value], array_values($this->frontDoor->all()));
        self::assertFalse($this->databases->exists($this->name), 'Its database stays its own: nothing on the server\'s.');

        $directory = $this->paths->site($this->name);
        self::assertSame($original, file_get_contents($directory.'/prod.env.before-server'), 'Its settings, as they were.');
        self::assertSame('0600', substr(\sprintf('%o', fileperms($directory.'/prod.env.before-server')), -4));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($directory.'/prod.env')), -4));
        self::assertSame(str_replace(
            "COMPOSE_FILE=compose.yaml:compose.prod.yaml\n",
            "COMPOSE_FILE='compose.yaml:compose.prod.yaml:compose.server.yaml'\n",
            $original,
        )."COMPOSE_PROJECT_NAME='{$this->name->value}'\n", file_get_contents($directory.'/prod.env'), 'Two lines changed, every other kept.');
        self::assertSame("services: {}\n", file_get_contents($directory.'/compose.yaml'), 'Its own files untouched.');

        $override = (string) file_get_contents($directory.'/compose.server.yaml');
        self::assertStringNotContainsString('quire-data', $override, 'Not on the server\'s PostgreSQL network.');
        self::assertStringNotContainsString('own-database', $override, 'Its PostgreSQL keeps running.');

        $operation = $this->operations->latest()[0];
        self::assertSame(['site.adopt', 'succeeded'], [$operation['action'], $operation['status']]);
        $log = implode("\n", $this->operations->lines($operation['id']));
        self::assertStringNotContainsString('Adm1nAppSecretValue', $log, 'Its secrets never reach the log.');
        self::assertStringNotContainsString('Pg0wnDatabasePassword', $log);
    }

    public function testAFailedAdoptionPutsTheSiteBackAsItWas(): void
    {
        $original = $this->siteInstalledByHand();
        $started = 0;
        $this->script['docker compose up'] = static function (array $command) use (&$started): ProcessResult {
            // Behind the front door it doesn't start; as it was, it does.
            return 1 === ++$started
                ? new ProcessResult(self::strings($command), 1, '', "container jane-php-1 is unhealthy\n")
                : self::ok($command, "Started\n");
        };

        try {
            $this->manager()->adopt($this->name, [], 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('unhealthy', $e->getMessage());
        }

        $directory = $this->paths->site($this->name);
        $restart = array_last($this->commands) ?? [];
        self::assertSame(['up', $directory.'/prod.env.before-server'], [$restart[8] ?? '', $restart[5] ?? ''], 'Started again with the settings it had.');
        self::assertContains('--no-build', $restart, 'With the image it had, unchecked: nothing new.');
        self::assertSame($original, file_get_contents($directory.'/prod.env'));
        self::assertFileDoesNotExist($directory.'/compose.server.yaml');
        self::assertSame([], $this->frontDoor->all());
        self::assertSame(Site::REMOVED, $this->registry->get($this->name)?->status, 'Not a site of the server: it can be adopted again.');
        self::assertSame('failed', $this->operations->latest()[0]['status']);
        self::assertFileExists($directory.'/compose.yaml', 'Its folder stays where it is.');
    }

    public function testAHostileSiteIsNeverStartedBehindTheFrontDoor(): void
    {
        $original = $this->siteInstalledByHand();
        $this->script['docker compose config'] = fn (array $command): ProcessResult => self::ok($command, json_encode(array_replace_recursive($this->config(), ['services' => ['database' => ['volumes' => [['type' => 'bind', 'source' => '/', 'target' => '/host']]]]]), \JSON_THROW_ON_ERROR));

        try {
            $this->manager()->adopt($this->name, [], 'cli', 'darko', static fn (): null => null);
            self::fail('It wasn\'t refused.');
        } catch (PolicyViolation $e) {
            self::assertStringContainsString('database', $e->getMessage());
        }

        self::assertNotContains('build', array_map(static fn (array $command): string => $command[8] ?? '', $this->commands));
        self::assertSame($original, file_get_contents($this->paths->site($this->name).'/prod.env'));
        self::assertSame([], $this->frontDoor->all());
    }

    public function testOnlyAProductionSiteNotYetAdoptedCanBe(): void
    {
        $manager = $this->manager();

        foreach ([
            'no prod.env' => [static fn (string $directory): bool => unlink($directory.'/prod.env'), 'has no prod.env'],
            'adopted already' => [static fn (string $directory): int|false => file_put_contents($directory.'/compose.server.yaml', ''), 'has a compose.server.yaml already'],
            'no domains' => [static fn (string $directory): int|false => file_put_contents($directory.'/prod.env', "APP_SECRET=x\n"), 'pass them with --domain'],
            'a foreign project' => [static fn (string $directory): int|false => file_put_contents($directory.'/prod.env', "SERVER_NAME=jane.example\nCOMPOSE_PROJECT_NAME='Jane Site'\n"), 'isn\'t one this server can manage'],
        ] as $case => [$change, $message]) {
            new Filesystem()->remove($this->paths->site($this->name));
            $this->siteInstalledByHand();
            $change($this->paths->site($this->name));

            try {
                $manager->adopt($this->name, [], 'cli', 'darko', static fn (): null => null);
                self::fail($case.': it was adopted.');
            } catch (\DomainException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }

        self::assertSame([], $this->commands, 'Nothing was run.');
        self::assertNull($this->registry->get($this->name));
    }

    public function testTheDomainsOfAServerName(): void
    {
        self::assertSame(['jane.example', 'www.jane.example', 'xn--bcher-kva.example'], array_map(
            static fn (Domain $domain): string => $domain->value,
            SiteManager::domainsOf('https://jane.example, www.jane.example:443, jane.example, Bücher.example, php:80, :80, localhost'),
        ));
    }

    /**
     * A site installed from the skeleton by hand, as docs/docker.md says: its prod.env as a person wrote it.
     *
     * @return string Its prod.env
     */
    private function siteInstalledByHand(): string
    {
        $directory = $this->paths->site($this->name);
        mkdir($directory, 0o755, true);
        file_put_contents($directory.'/compose.yaml', "services: {}\n");
        file_put_contents($directory.'/compose.prod.yaml', "services: {}\n");
        $env = "# My site\nCOMPOSE_FILE=compose.yaml:compose.prod.yaml\nSERVER_NAME=jane.example, www.jane.example\nAPP_SECRET=Adm1nAppSecretValue\nPOSTGRES_PASSWORD=Pg0wnDatabasePassword\nMAILER_DSN=\n";
        file_put_contents($directory.'/prod.env', $env);
        chmod($directory.'/prod.env', 0o600);
        $this->ownDatabase = true;

        return $env;
    }

    /**
     * @return list<list<string>> The commands run, so far
     */
    private function commands(): array
    {
        return $this->commands;
    }

    private function assertNothingLeft(): void
    {
        self::assertSame(Site::REMOVED, $this->registry->get($this->name)?->status, 'Recorded as removed: name and domains free.');
        self::assertSame([], $this->registry->servedDomains());
        self::assertSame([], $this->frontDoor->all(), 'Nothing opened.');
        self::assertFalse($this->databases->exists($this->name), 'Its database dropped.');
        self::assertFileDoesNotExist($this->paths->site($this->name));
    }

    private function manager(): SiteManager
    {
        $runner = new readonly class($this) implements ProcessRunner {
            public function __construct(private SiteManagerTest $test)
            {
            }

            #[\Override]
            public function run(array $command, array $env = [], ?string $directory = null, float $timeout = 600, ?\Closure $output = null, ?string $input = null, ?string $inputFile = null): ProcessResult
            {
                $result = $this->test->respond($command, $env);

                if ($output instanceof \Closure) {
                    $output($result->output.$result->errorOutput);
                }

                return $result;
            }
        };

        return new SiteManager(
            $this->registry,
            $this->operations,
            new SiteLock($this->connection),
            new SkeletonInstaller($runner),
            $this->databases,
            new \Quire\Server\Site\SiteFiles(),
            new Compose($runner, new ComposePolicy(), new RuntimeImages($runner), new RuntimeBuilds($this->connection, $this->schema), 'tcp://docker-proxy:2375'),
            $this->frontDoor,
            $this->paths,
            new Git($runner, $keys = new DeployKeys($runner, $this->paths)),
            new SiteGit($this->connection, $this->schema),
            $keys,
            'smtp://mailer:25',
        );
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $env
     *
     * @internal Called by the scripted runner
     */
    public function respond(array $command, array $env): ProcessResult
    {
        $this->commands[] = $command;

        if ('docker' === $command[0]) {
            // Every Docker command goes through the proxy, with nothing inherited choosing its files.
            self::assertSame('tcp://docker-proxy:2375', $env['DOCKER_HOST'] ?? null);
            self::assertFalse($env['COMPOSE_FILE'] ?? null);
            // Its own settings, or (putting an adopted site back) the copy of those it had before.
            self::assertContains($command[5], [$this->paths->site($this->name).'/prod.env', $this->paths->site($this->name).'/prod.env.before-server']);
            self::assertSame(['--project-directory', $this->paths->site($this->name), '--env-file', $command[5], '--project-name', $this->name->value], \array_slice($command, 2, 6));
        }

        $key = 'docker' === $command[0] ? 'docker compose '.$command[8] : $command[0].' '.$command[1];

        return ($this->script[$key] ?? throw new \LogicException('Unexpected command: '.implode(' ', $command)))($command);
    }

    /**
     * A site's folder and its quire:backup file, as a backup off the server has them.
     *
     * @return array{string, string}
     */
    private function backedUpSite(): array
    {
        $code = $this->root.'/restored/srv/quire/sites/old-site';
        $filesystem = new Filesystem();

        foreach ([
            'compose.yaml' => 'services: {}',
            'compose.prod.yaml' => 'services: {}',
            'composer.json' => '{"require": {"quire/core": "^0.1"}}',
            'composer.lock' => '{"packages": []}',
            'src/Kernel.php' => '<?php // the site\'s own code',
            'prod.env' => "APP_SECRET='the-old-secret'\nQUIRE_DATABASE_PASSWORD='the-old-password'\n",
            'prod.env.before-server' => "APP_SECRET='the-old-secret'\n",
            'compose.server.yaml' => '# old-site',
        ] as $path => $content) {
            $filesystem->dumpFile($code.'/'.$path, $content);
        }

        $backup = $this->root.'/restored/srv/quire/backups/old-site/off-server.tar';
        $filesystem->dumpFile($backup, 'backup');

        return [$code, $backup];
    }

    private function request(): SiteRequest
    {
        return new SiteRequest($this->name, [new Domain('jane.example'), new Domain('www.jane.example')], 'Jane\'s notes', 'jane@example.com', false, '^0.1');
    }

    /**
     * @return array<string, mixed> The site's configuration as Compose would resolve it
     */
    private function config(): array
    {
        $directory = $this->paths->site($this->name);
        $build = ['context' => $directory, 'dockerfile' => 'Dockerfile'];

        if ($this->ownDatabase) {
            return [
                'name' => $this->name->value,
                'networks' => ['default' => ['name' => $this->name->value.'_default'], 'quire-edge' => ['name' => 'quire-edge', 'external' => true]],
                'services' => [
                    'php' => ['build' => $build, 'networks' => ['default' => null, 'quire-edge' => ['aliases' => ['jane.example', 'www.jane.example']]], 'volumes' => [['type' => 'volume', 'source' => 'storage_data', 'target' => '/app/storage']]],
                    'worker' => ['build' => $build, 'networks' => ['default' => null]],
                    'database' => ['image' => 'postgres:18-alpine', 'networks' => ['default' => null], 'volumes' => [['type' => 'volume', 'source' => 'database_data', 'target' => '/var/lib/postgresql']]],
                ],
                'volumes' => ['storage_data' => ['name' => $this->name->value.'_storage_data'], 'database_data' => ['name' => $this->name->value.'_database_data']],
            ];
        }

        return [
            'name' => $this->name->value,
            'networks' => ['default' => ['name' => $this->name->value.'_default'], 'quire-edge' => ['name' => 'quire-edge', 'external' => true], 'quire-data' => ['name' => 'quire-data', 'external' => true]],
            'services' => [
                'php' => ['build' => $build, 'networks' => ['default' => null, 'quire-edge' => ['aliases' => ['jane.example', 'www.jane.example']], 'quire-data' => null], 'volumes' => [['type' => 'volume', 'source' => 'storage_data', 'target' => '/app/storage']]],
                'worker' => ['build' => $build, 'networks' => ['default' => null, 'quire-data' => null]],
            ],
            'volumes' => ['storage_data' => ['name' => $this->name->value.'_storage_data']],
        ];
    }

    /**
     * @param array<mixed> $command
     */
    private static function ok(array $command, string $output): ProcessResult
    {
        return new ProcessResult(self::strings($command), 0, $output, '');
    }

    /**
     * @param array<mixed> $command
     */
    private static function argument(array $command, int $index): string
    {
        return self::strings($command)[$index] ?? '';
    }

    /**
     * @param array<mixed> $command
     *
     * @return list<string>
     */
    private static function strings(array $command): array
    {
        return array_values(array_map(static fn (mixed $part): string => \is_scalar($part) ? (string) $part : '', $command));
    }

    private function envValue(string $env, string $name): string
    {
        return 1 === preg_match(\sprintf("/^%s='([^']*)'\$/m", $name), $env, $match) ? $match[1] : 'not there';
    }
}
