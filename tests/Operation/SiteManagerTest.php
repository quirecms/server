<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Operation;

use Quire\Server\Backup\SiteDatabaseDumps;
use Quire\Server\Database\SiteDatabases;
use Quire\Server\Docker\Builders;
use Quire\Server\Docker\Compose;
use Quire\Server\Docker\ComposeFiles;
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
use Quire\Server\Registry\RuntimeFloors;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\GateFixture;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Adding and removing sites, with Docker and Composer scripted (what they'd print), and the real
 * registry, PostgreSQL, files and front door folder: above all, what's undone when a step fails.
 */
final class SiteManagerTest extends RegistryTestCase
{
    /** @var array<string, string> The BuildKit builders' containers there are, and the BuildKit each was created with (Builders) */
    private array $builders = [];

    /** @var list<string> The builders registered in the site's Docker configuration */
    private array $registered = [];

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
                file_put_contents(self::argument($command, 11), 'backup');

                return self::ok($command, '');
            },
            'docker compose down' => static fn (array $command): ProcessResult => self::ok($command, "Removed\n"),
            // Each site's own BuildKit (Builders), created on the gate's trusted socket
            'docker container inspect' => fn (array $command): ProcessResult => isset($this->builders[self::argument($command, 5)]) ? self::ok($command, $this->builders[self::argument($command, 5)].' true') : new ProcessResult(self::strings($command), 1, '', 'Error: No such container'),
            'docker container run' => function (array $command): ProcessResult {
                $this->builders[self::argument($command, 5)] = Builders::BUILDKIT;

                return self::ok($command, "abc123\n");
            },
            'docker container rm' => function (array $command): ProcessResult {
                unset($this->builders[self::argument($command, 4)]);

                return self::ok($command, '');
            },
            'docker network inspect' => static fn (array $command): ProcessResult => self::ok($command, ''),
            // Registered in the site's Docker configuration (its socket)
            'docker buildx inspect' => fn (array $command): ProcessResult => '--bootstrap' === self::argument($command, 3) || \in_array(self::argument($command, 3), $this->registered, true) ? self::ok($command, '') : new ProcessResult(self::strings($command), 1, '', 'ERROR: no builder found'),
            'docker buildx create' => function (array $command): ProcessResult {
                $this->registered[] = self::argument($command, 4);

                return self::ok($command, '');
            },
            'docker volume rm' => static fn (array $command): ProcessResult => self::ok($command, ''),
            // The server's PostgreSQL, where a site's database is dumped and restored (SiteDatabaseDumps)
            'docker ps --quiet' => static fn (array $command): ProcessResult => self::ok($command, "abcdef123456\n"),
            'docker exec -e' => static fn (array $command): ProcessResult => self::ok($command, ''),
            'docker cp --quiet' => static function (array $command): ProcessResult {
                file_put_contents(self::argument($command, 4), 'the site\'s own tables');

                return self::ok($command, '');
            },
            'docker exec abcdef123456' => static fn (array $command): ProcessResult => self::ok($command, ''),
            'docker exec --interactive' => static fn (array $command): ProcessResult => self::ok($command, ''),
            ...GateFixture::networkScript(),
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
        self::assertSame(['config', 'build', 'config', 'up', 'exec', 'exec', 'cp'], array_map(static fn (array $command): string => $command[8], $this->commands()));
        self::assertFileExists($this->paths->site($this->name).'/composer.lock', 'The versions its image was built with, recorded in its folder.');

        $env = (string) file_get_contents($this->paths->site($this->name).'/prod.env');
        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->paths->site($this->name).'/prod.env')), -4));
        self::assertMatchesRegularExpression("/^QUIRE_DATABASE_PASSWORD='[0-9a-f]{48}'\$/m", $env);
        self::assertMatchesRegularExpression("/^APP_SECRET='[0-9a-f]{64}'\$/m", $env);
        $override = (string) file_get_contents($this->paths->site($this->name).'/compose.server.yaml');
        self::assertStringContainsString('${QUIRE_DATABASE_PASSWORD}', $override, 'It names the variable…');
        // On its own link (D-34): the front door finds it there by its domains, PostgreSQL is there,
        // and the front door's address is the only proxy it trusts
        /** @var array{services: array{php: array{networks: mixed, environment: array<string, mixed>}, worker: array{networks: mixed}}, networks: array<string, mixed>} $written */
        $written = Yaml::parse($override, Yaml::PARSE_CUSTOM_TAGS);
        $link = 'quire-link-'.$this->name->value;
        self::assertSame([
            'php' => ['default' => null, $link => ['aliases' => ['jane.example.quire.internal', 'www.jane.example.quire.internal']]],
            'worker' => ['default' => null, $link => null],
            'link' => ['external' => true],
            'own' => ['ipam' => ['config' => [['subnet' => '10.72.0.32/27']]]],
            'proxies' => '10.72.0.2',
        ], [
            'php' => $written['services']['php']['networks'] ?? null,
            'worker' => $written['services']['worker']['networks'] ?? null,
            'link' => $written['networks'][$link] ?? null,
            'own' => $written['networks']['default'] ?? null,
            'proxies' => $written['services']['php']['environment']['SYMFONY_TRUSTED_PROXIES'] ?? null,
        ]);
        self::assertDoesNotMatchRegularExpression('/\bquire-(edge|data)\b/', $override, 'None of the networks all sites shared.');
        self::assertStringNotContainsString('private_ranges', $override);
        self::assertStringNotContainsString($this->envValue($env, 'QUIRE_DATABASE_PASSWORD'), $override, '…never the secret: the override holds none.');
        self::assertStringNotContainsString($this->envValue($env, 'APP_SECRET'), $override);

        $operation = $this->operations->latest()[0];
        self::assertSame(['site.add', 'succeeded', 'darko'], [$operation['action'], $operation['status'], $operation['requested_by']]);
        $log = implode("\n", $this->operations->lines($operation['id']));
        self::assertStringNotContainsString('Sup3rSecretAdminPw', $log, 'The admin\'s password never reaches the log.');
        self::assertStringContainsString('[secret]', $log);
        self::assertStringNotContainsString($this->envValue($env, 'QUIRE_DATABASE_PASSWORD'), $log);
        self::assertStringNotContainsString($this->envValue($env, 'APP_SECRET'), $log);
    }

    public function testAFailedInstallUndoesEverything(): void
    {
        $this->script['docker compose exec'] = static fn (array $command): ProcessResult => 'quire:install' === ($command[12] ?? '')
            ? new ProcessResult(self::strings($command), 1, '', "The database refused the connection.\n")
            : self::ok($command, '{"contract": 1}');

        try {
            $this->manager()->add($this->request(), 'cli', 'darko', static fn (): null => null);
            self::fail('A site that didn\'t install was added.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('The database refused the connection', $e->getMessage());
        }

        $this->assertNothingLeft();
        self::assertSame('failed', $this->operations->latest()[0]['status']);
    }

    public function testAFailedBuildUndoesEverything(): void
    {
        $this->script['docker compose build'] = static fn (array $command): ProcessResult => new ProcessResult(self::strings($command), 1, '', "no space left on device\n");
        // Undone last first: its containers stopped while its folder (their Compose files) and database are there
        $at = [];
        $this->script['docker compose down'] = function (array $command) use (&$at): ProcessResult {
            $at = [is_file($this->paths->site($this->name).'/compose.yaml'), $this->databases->exists($this->name)];

            return self::ok($command, '');
        };

        try {
            $this->manager()->add($this->request(), 'cli', 'darko', static fn (): null => null);
            self::fail('It didn\'t fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no space left on device', $e->getMessage());
        }

        $this->assertNothingLeft();
        self::assertContains('down', array_map(static fn (array $command): string => $command[8] ?? '', $this->commands), 'Its containers and volumes removed.');
        self::assertSame([true, true], $at, 'Last first: stopped before its folder and database went.');
        self::assertSame('failed', $this->operations->latest()[0]['status']);

        // Its name and domains are free to try again.
        unset($this->script['docker compose build']);
        $this->script['docker compose build'] = static fn (array $command): ProcessResult => self::ok($command, '');
        self::assertSame(Site::RUNNING, $this->manager()->add($this->request(), 'cli', 'darko', static fn (): null => null)->site->status);
    }

    public function testCodeQuireDidntReleaseIsNeverBuilt(): void
    {
        // Packagist serves quire/core 0.1.3 from another commit than the one Quire released (a moved tag)
        $this->script['composer create-project'] = static function (array $command): ProcessResult {
            mkdir(self::argument($command, 3), 0o755, true);
            file_put_contents(self::argument($command, 3).'/compose.yaml', "services: {}\n");
            $commit = str_repeat('e', 40);
            file_put_contents(self::argument($command, 3).'/composer.lock', (string) json_encode(['packages' => [['name' => 'quire/core', 'version' => 'v0.1.3', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/quirecms/core/zipball/'.$commit, 'reference' => $commit]]]]));

            return self::ok($command, "Installing quire/skeleton (v0.1.3)\n");
        };
        $this->script['curl -fsSL'] = static function (array $command): ProcessResult {
            if (\in_array('%{url_effective}', $command, true)) {
                return self::ok($command, 'https://github.com/quirecms/framework/releases/tag/v0.1.3');
            }

            $at = array_search('-o', $command, true);
            $last = self::argument($command, \count($command) - 1);
            $release = ['version' => '0.1.3', 'date' => '2026-11-04', 'security' => [], 'notes' => 'N.', 'upgrade' => null, 'requires' => ['php' => '>=8.5.11', 'postgresql' => 18, 'server_contract' => 1], 'packages' => ['quire/core' => str_repeat('d', 40)]];
            file_put_contents(self::argument($command, \is_int($at) ? $at + 1 : 0), str_ends_with($last, '.json') && !str_ends_with($last, 'sigstore.json') ? (string) json_encode(['schema' => 1, 'releases' => [$release]]) : '{}');

            return self::ok($command, '');
        };
        $this->script['cosign verify-blob'] = static fn (array $command): ProcessResult => self::ok($command, '');

        try {
            $this->manager(checksCode: true)->add($this->request(), 'cli', 'darko', static fn (): null => null);
            self::fail('It was built.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('quire/core 0.1.3 isn\'t the code Quire released', $e->getMessage());
        }

        self::assertNotContains('build', array_map(static fn (array $command): string => $command[8] ?? '', $this->commands), 'Nothing was built.');
        $this->assertNothingLeft();
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
        self::assertStringContainsString("COMPOSE_FILE='compose.yaml:compose.prod.yaml:compose.server.yaml'", $env, 'The server\'s.');
        self::assertStringNotContainsString('elsewhere', $env);
        // Its owner's own settings, as they were
        self::assertStringContainsString("QUIRE_STORAGE_BUCKET='jane-files'", $env);
        self::assertStringContainsString("MAILER_DSN='smtp://mail.example.com:587'", $env, 'Its own, over the server\'s default.');
        self::assertStringNotContainsString('smtp://mailer:25', $env);
        self::assertMatchesRegularExpression("/^APP_SECRET='[0-9a-f]{64}'\$/m", $env);
        self::assertStringNotContainsString('old-site', (string) file_get_contents($directory.'/compose.server.yaml'));
        self::assertTrue($this->databases->exists($this->name));

        // Its data restored, streamed in; never installed afresh.
        $consoles = array_map(static fn (array $command): string => $command[12] ?? '', array_filter($this->commands, static fn (array $command): bool => 'exec' === ($command[8] ?? null)));
        self::assertContains('quire:restore', $consoles);
        self::assertNotContains('quire:install', $consoles);
        self::assertSame([$this->name->value, $this->name->value], array_values($this->frontDoor->all()));
        self::assertSame(['site.restore_as_new', 'succeeded'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status']]);
        // Checked, built and started with the server's override, like any site
        self::assertSame(['config', 'build', 'config', 'up'], \array_slice(array_values(array_filter(array_map(static fn (array $command): string => $command[8] ?? '', $this->commands()), static fn (string $sub): bool => \in_array($sub, ['config', 'build', 'up'], true))), 0, 4));
        self::assertStringContainsString('quire-link-'.$this->name->value, (string) file_get_contents($directory.'/compose.server.yaml'), 'On its own link.');
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
        self::assertNull(new SiteNetworks($this->connection, $this->schema)->find($this->name), 'Its addresses are free again.');
        self::assertContains(['docker', 'network', 'rm', 'quire-link-'.$this->name->value], array_map(array_values(...), $this->commands), 'Its link is gone.');
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
        // The commit (and none of the server's files in it)
        $this->script['git -C'] = static fn (array $command): ProcessResult => self::ok($command, \in_array('ls-tree', self::strings($command), true) ? '' : $commit."\n");

        $site = $this->manager()->restoreAsNew($this->name, $this->request()->domains, new Repository('https://example.com/jane.git', 'main'), $backup, [], 'cli', 'darko', static fn (): null => null, poll: true);

        self::assertSame(Site::RUNNING, $site->status);
        $git = new SiteGit($this->connection, $this->schema)->of($this->name);
        self::assertSame(['https://example.com/jane.git', 'main', $commit, true], [$git['repository']->url ?? null, $git['repository']->branch ?? null, $git['deployed'] ?? null, $git['poll'] ?? null]);
        self::assertContains('quire:restore', array_map(static fn (array $command): string => $command[12] ?? '', $this->commands));

        // Removed, its deploy key (which reads its repository) and its Git settings go with it.
        new Filesystem()->dumpFile($this->paths->keys($this->name).'/id_ed25519', 'its key');
        $this->manager()->remove($this->name, false, 'cli', 'darko', static fn (): null => null);
        self::assertDirectoryDoesNotExist($this->paths->keys($this->name));
        self::assertNull(new SiteGit($this->connection, $this->schema)->of($this->name));
        self::assertSame(['site.remove', 'succeeded'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status']]);

        $this->expectExceptionMessageIsOrContains('There\'s no site named');
        $this->manager()->remove($this->name, false, 'cli', 'darko', static fn (): null => null);
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

    public function testEachSiteIsBuiltByARootlessBuilderOfItsOwnThatGoesWithIt(): void
    {
        $container = 'quire-buildkit-'.$this->name->value;
        // One of an older BuildKit, and another site's whose name starts with this one's
        $this->builders = [$container => 'moby/buildkit:v0.33.0-rootless@sha256:'.str_repeat('0', 64), $container.'-blog' => Builders::BUILDKIT];
        $manager = $this->manager();
        $manager->add($this->request(), 'cli', 'darko', static fn (): null => null);

        self::assertContains(['docker', 'container', 'rm', '--force', $container], $this->dockerCommands(), 'The older one goes.');
        $run = array_first(array_filter($this->dockerCommands(), static fn (array $command): bool => 'run' === ($command[2] ?? null))) ?? [];
        self::assertSame([
            'docker', 'container', 'run', '--detach', '--name', $container, '--label', Builders::LABEL.'='.$this->name->value, '--label', 'com.quirecms.server.buildkit='.Builders::BUILDKIT,
            '--network', 'quire-sandbox',
            '--security-opt', 'seccomp=unconfined', '--security-opt', 'systempaths=unconfined', '--security-opt', 'no-new-privileges=false',
            '--pids-limit', '4096',
            '--volume', $container.'-state:/home/user/.local/share/buildkit',
            Builders::BUILDKIT,
        ], $run, 'Not privileged, no capabilities, no host paths or ports; no AppArmor option on a host without it.');
        self::assertMatchesRegularExpression('/^moby\/buildkit:v[0-9.]+-rootless@sha256:[0-9a-f]{64}\z/', Builders::BUILDKIT, 'Rootless, pinned by digest.');
        self::assertContains(['docker', 'buildx', 'create', '--name', 'site', '--driver', 'remote', 'docker-container://'.$container.'?context=quire'], $this->dockerCommands(), 'Reached through the site\'s own socket.');
        $build = array_first(array_filter($this->commands(), static fn (array $command): bool => 'build' === ($command[8] ?? null))) ?? [];
        $at = array_search('--builder', $build, true);
        self::assertIsInt($at);
        self::assertSame('site', $build[$at + 1] ?? null);
        self::assertEqualsCanonicalizing([$container => Builders::BUILDKIT, $container.'-blog' => Builders::BUILDKIT], $this->builders, 'The other site\'s stays.');

        $manager->remove($this->name, false, 'cli', 'darko', static fn (): null => null);

        self::assertSame([$container.'-blog' => Builders::BUILDKIT], $this->builders, 'Its own goes with it, with its cache.');
        self::assertContains(['docker', 'volume', 'rm', '--force', $container.'-state'], $this->dockerCommands());
        self::assertFileDoesNotExist($this->root.'/gate/scopes/'.$this->name->value.'.json', 'Its socket on the gate goes too.');
    }

    public function testARemovedSiteComesBackWithItsDataAndDomains(): void
    {
        $this->skeletonWithItsFiles();
        $manager = $this->manager();
        $manager->add($this->request(), 'cli', 'darko', static fn (): null => null);

        try {
            $manager->restoreRemoved($this->name, [], 'cli', 'darko', static fn (): null => null);
            self::fail('A running site was brought back.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('isn\'t removed: it\'s running', $e->getMessage());
        }

        $final = $manager->remove($this->name, true, 'cli', 'darko', static fn (): null => null);
        $this->commands = [];

        $restored = $manager->restoreRemoved($this->name, [], 'cli', 'darko', static fn (): null => null);

        self::assertSame(Site::RUNNING, $restored['site']->status);
        self::assertSame(['jane.example', 'www.jane.example'], array_map(static fn (Domain $domain): string => $domain->value, $restored['site']->domains), 'As it had them, the main one first.');
        self::assertSame($final, $restored['backup']);
        self::assertDirectoryExists($restored['folder'], 'Its archive stays: it can be brought back again.');
        self::assertFileExists($this->paths->site($this->name).'/composer.json', 'Its code, from its folder.');
        self::assertContains('quire:restore', $this->consoles(), 'Its data, from its final backup.');
        self::assertSame(['site.restore_as_new', 'succeeded'], [$this->operations->latest()[0]['action'], $this->operations->latest()[0]['status']]);
    }

    public function testARemovedSiteWithoutItsFinalBackupOrFromGitIsntBroughtBack(): void
    {
        $this->skeletonWithItsFiles();
        $manager = $this->manager();
        $manager->add($this->request(), 'cli', 'darko', static fn (): null => null);
        $manager->remove($this->name, false, 'cli', 'darko', static fn (): null => null);

        try {
            $manager->restoreRemoved($this->name, [], 'cli', 'darko', static fn (): null => null);
            self::fail('It was brought back without its data.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('was removed without a final backup: its data is gone', $e->getMessage());
        }

        // A Git mode site's folder has its repository: it comes from there
        $archives = glob($this->paths->sites().'/.removed/'.$this->name->value.'-*');
        $archive = false === $archives ? '' : ($archives[0] ?? '');
        mkdir($archive.'/.git');
        new Filesystem()->dumpFile($this->paths->backups($this->name).'/final-20261104-120000.tar', 'backup');

        $this->expectExceptionMessageIsOrContains('followed a Git repository: add it from there');
        $manager->restoreRemoved($this->name, [], 'cli', 'darko', static fn (): null => null);
    }

    public function testOnlyThatSitesArchiveIsTaken(): void
    {
        // jane-blog's archive is no archive of jane's
        $other = new SiteName($this->name->value.'-blog');
        $this->registry->add($this->name, [new Domain('jane.example')], Site::SHARED_DATABASE, $this->name->value);
        $this->registry->setStatus($this->name, Site::REMOVED);
        new Filesystem()->mkdir($this->paths->sites().'/.removed/'.$other->value.'-20261104-120000');
        new Filesystem()->dumpFile($this->paths->backups($this->name).'/final-20261104-120000.tar', 'backup');

        $this->expectExceptionMessageIsOrContains('folder isn\'t in');
        $this->manager()->restoreRemoved($this->name, [], 'cli', 'darko', static fn (): null => null);
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
        // The rest of its database too, as its own role, beside its backup
        $dump = array_first(array_filter($this->dockerCommands(), static fn (array $command): bool => \in_array('pg_dump', $command, true))) ?? [];
        self::assertContains('--username='.$this->name->databaseIdentifier(), $dump, 'As the site\'s own role.');
        self::assertContains('--exclude-schema=quire', $dump, 'quire:backup has Quire\'s own schema.');
        self::assertSame([$this->name->databaseIdentifier()], \array_slice($dump, -1));
        $kept = substr($backup, 0, -4).'.database.dump';
        self::assertSame('the site\'s own tables', file_get_contents($kept));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($kept)), -4));
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

    public function testABackupThatIsALinkIsntTaken(): void
    {
        $manager = $this->manager();
        $manager->add($this->request(), 'cli', 'darko', static fn (): null => null);
        // The site's container made its backup a link to another site's secrets, which Docker copies as one.
        $secrets = $this->paths->site(new SiteName('other')).'/prod.env';
        new Filesystem()->dumpFile($secrets, 'POSTGRES_PASSWORD=the other site\'s');
        $this->script['docker compose cp'] = static function (array $command) use ($secrets): ProcessResult {
            self::assertSame('--follow-link', $command[9], 'Followed in the container, among its own files.');
            symlink($secrets, self::argument($command, 11));

            return self::ok($command, '');
        };

        try {
            $manager->remove($this->name, true, 'cli', 'darko', static fn (): null => null);
            self::fail('It was removed with a link for a backup.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('isn\'t a plain file', $e->getMessage());
        }

        $kept = glob($this->paths->backups($this->name).'/{,.}*', \GLOB_BRACE);
        self::assertSame([], array_filter(false === $kept ? [] : $kept, static fn (string $file): bool => !\in_array(basename($file), ['.', '..'], true)), 'Nothing of it kept.');
        self::assertSame(Site::RUNNING, $this->registry->get($this->name)?->status);
    }

    public function testABackupCutShortLeavesNothingThatStopsTheNext(): void
    {
        $manager = $this->manager();
        $manager->add($this->request(), 'cli', 'darko', static fn (): null => null);
        $this->commands = [];
        $this->script['docker compose exec'] = static fn (array $command): ProcessResult => 'quire:backup' === ($command[12] ?? '')
            ? new ProcessResult(self::strings($command), 1, '', 'No space left on device')
            : self::ok($command, '');

        try {
            $manager->remove($this->name, true, 'cli', 'darko', static fn (): null => null);
            self::fail('It was removed without a backup.');
        } catch (\RuntimeException) {
        }

        $exec = array_values(array_filter($this->commands(), static fn (array $command): bool => 'exec' === ($command[8] ?? '')));
        $file = $exec[0][13] ?? '';
        self::assertMatchesRegularExpression('#^/tmp/quire-final-backup-[0-9a-f]{12}\.tar\z#', $file, 'A name of its own: one left by an earlier backup is never in the way.');
        self::assertSame(['rm', '-f', $file], \array_slice($exec[1] ?? [], 11), 'Removed even though the backup failed.');
    }

    public function testASiteWhoseAddWasCutShortBeforeItsFolderCanBeRemoved(): void
    {
        // The agent stopped right after recording it: no folder, no database, its name and domains taken
        $this->registry->add($this->name, $this->request()->domains, Site::SHARED_DATABASE, $this->name->value);

        $this->manager()->remove($this->name, true, 'cli', 'darko', static fn (): null => null);

        self::assertSame(Site::REMOVED, $this->registry->get($this->name)?->status);
        self::assertContains('Its folder isn\'t there: nothing to back up', $this->operations->lines($this->operations->latest()[0]['id']));
        self::assertSame([], $this->commands(), 'Nothing to stop.');
    }

    public function testSitesLeftBeingCreatedAreRemovedAtTheAgentsStart(): void
    {
        $manager = $this->manager();
        $manager->add($this->request(), 'cli', 'darko', static fn (): null => null);
        $unfinished = new SiteName('t'.bin2hex(random_bytes(3)).'-kate');
        $this->registry->add($unfinished, [new Domain('kate.example')], Site::SHARED_DATABASE, $unfinished->value);

        self::assertSame([$unfinished->value], $manager->removeUnfinished());
        self::assertSame(Site::REMOVED, $this->registry->get($unfinished)?->status);
        self::assertSame(Site::RUNNING, $this->registry->get($this->name)?->status, 'A site that runs is left alone.');
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
        self::assertSame(['exec', 'cp', 'exec', 'exec', 'cp', 'exec', 'config', 'build', 'config', 'up', 'cp'], array_map(static fn (array $command): string => $command[8] ?? '', $this->commands()));
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
        $written = Yaml::parse($override, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($written);
        self::assertIsArray($written['services'] ?? null);
        foreach (['php', 'worker', 'database'] as $service) {
            self::assertIsArray($written['services'][$service] ?? null);
            self::assertSame('unless-stopped', $written['services'][$service]['restart'] ?? null, $service.' comes back after a reboot, its own PostgreSQL too.');
        }

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
        $restart = array_last(array_values(array_filter($this->commands, static fn (array $command): bool => 'compose' === ($command[1] ?? null)))) ?? [];
        self::assertSame(['up', $directory.'/prod.env.before-server'], [$restart[8] ?? '', $restart[5] ?? ''], 'Started again with the settings it had.');
        self::assertContains('--no-build', $restart, 'With the image it had, unchecked: nothing new.');
        self::assertSame($original, file_get_contents($directory.'/prod.env'));
        self::assertFileDoesNotExist($directory.'/compose.server.yaml');
        self::assertSame([], $this->frontDoor->all());
        self::assertNull(new SiteNetworks($this->connection, $this->schema)->find($this->name), 'Its addresses are free again.');
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
            // It would own the server's containers, and PostgreSQL's data with every site's database.
            'the server\'s own project' => [static fn (string $directory): int|false => file_put_contents($directory.'/prod.env', "SERVER_NAME=jane.example\nCOMPOSE_PROJECT_NAME=quire-server\n"), 'is reserved for the server itself'],
            // "jane_" would be the start of its names and of project "jane_db"'s
            'a project with "_"' => [static fn (string $directory): int|false => file_put_contents($directory.'/prod.env', "SERVER_NAME=jane.example\nCOMPOSE_PROJECT_NAME=jane_db\n"), 'isn\'t a site name'],
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
        return array_values(array_filter($this->commands, static fn (array $command): bool => 'compose' === ($command[1] ?? null)));
    }

    /**
     * @return list<list<string>> The Docker commands that aren't Compose's (the server's PostgreSQL)
     */
    private function dockerCommands(): array
    {
        return array_values(array_filter($this->commands, static fn (array $command): bool => 'docker' === $command[0] && 'compose' !== ($command[1] ?? null)));
    }

    private function assertNothingLeft(): void
    {
        self::assertSame(Site::REMOVED, $this->registry->get($this->name)?->status, 'Recorded as removed: name and domains free.');
        self::assertSame([], $this->registry->servedDomains());
        self::assertSame([], $this->frontDoor->all(), 'Nothing opened.');
        self::assertFalse($this->databases->exists($this->name), 'Its database dropped.');
        self::assertFileDoesNotExist($this->paths->site($this->name));
    }

    private function manager(bool $checksCode = false): SiteManager
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
            new SiteLock($this->connection, $this->schema),
            new SkeletonInstaller($runner),
            $this->databases,
            new \Quire\Server\Site\SiteFiles(),
            $compose = new Compose($runner, new ComposePolicy(), new ComposeFiles(), new RuntimeImages($runner, new RuntimeFloors($this->connection, $this->schema)), new RuntimeBuilds($this->connection, $this->schema), GateFixture::gate($runner, $this->root, $this->name->value, 'kim', 'old-site'), GateFixture::builders($runner, $this->root), $checksCode ? new \Quire\Server\Update\CodeCheck(new \Quire\Server\Release\QuireFeed(new \Quire\Server\Release\GitHubReleases($runner))) : null),
            $this->frontDoor,
            $this->paths,
            new Git($runner, $keys = new DeployKeys($runner, $this->paths), new \Quire\Server\Git\GitHosts(resolve: static fn (): array => ['140.82.121.4'])),
            new SiteGit($this->connection, $this->schema),
            $keys,
            new SiteDatabaseDumps($runner, $compose, GateFixture::TRUSTED),
            GateFixture::links($runner, $this->connection, $this->schema),
            new SiteNetworks($this->connection, $this->schema),
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

        // Every Docker command goes through the gate: a site's on its own socket (but putting an adopted
        // site back as it was), the agent's own on the trusted one.
        if ('docker' === $command[0]) {
            $site = ('compose' === $command[1] && !str_ends_with($command[5], '.before-server')) || 'buildx' === $command[1];
            self::assertTrue($site ? GateFixture::isSite($env, $this->root, $this->name->value) : GateFixture::TRUSTED === ($env['DOCKER_HOST'] ?? null), implode(' ', $command));
        }

        // Compose's, with nothing inherited choosing its files.
        if ('docker' === $command[0] && 'compose' === $command[1]) {
            self::assertFalse($env['COMPOSE_FILE'] ?? null);
            // Its own settings, or (putting an adopted site back) the copy of those it had before.
            self::assertContains($command[5], [$this->paths->site($this->name).'/prod.env', $this->paths->site($this->name).'/prod.env.before-server']);
            self::assertSame(['--project-directory', $this->paths->site($this->name), '--env-file', $command[5], '--project-name', $this->name->value], \array_slice($command, 2, 6));
        }

        $key = match (true) {
            'docker' === $command[0] && 'compose' === $command[1] => 'docker compose '.$command[8],
            'docker' === $command[0] => 'docker '.$command[1].' '.$command[2],
            default => $command[0].' '.$command[1],
        };

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
            'prod.env' => "COMPOSE_FILE='compose.yaml:../elsewhere.yaml'\nAPP_SECRET='the-old-secret'\nQUIRE_DATABASE_PASSWORD='the-old-password'\n# Its owner's own\nMAILER_DSN='smtp://mail.example.com:587'\nQUIRE_STORAGE_BUCKET='jane-files'\n",
            'prod.env.before-server' => "APP_SECRET='the-old-secret'\n",
            'compose.server.yaml' => '# old-site',
        ] as $path => $content) {
            $filesystem->dumpFile($code.'/'.$path, $content);
        }

        $backup = $this->root.'/restored/srv/quire/backups/old-site/off-server.tar';
        $filesystem->dumpFile($backup, 'backup');

        return [$code, $backup];
    }

    /**
     * @return list<string> The site's console commands run so far, e.g. "quire:restore"
     */
    private function consoles(): array
    {
        /** @var list<list<string>> $commands Run since the last reset (a property: written by respond()) */
        $commands = $this->commands;

        return array_values(array_map(static fn (array $command): string => $command[12] ?? '', array_filter($commands, static fn (array $command): bool => 'exec' === ($command[8] ?? null))));
    }

    /** A skeleton with the files a Quire site's folder has (a restore checks for them) */
    private function skeletonWithItsFiles(): void
    {
        $this->script['composer create-project'] = static function (array $command): ProcessResult {
            mkdir(self::argument($command, 3), 0o755, true);

            foreach (['compose.yaml' => "services: {}\n", 'compose.prod.yaml' => "services: {}\n", 'composer.json' => '{}'] as $file => $content) {
                file_put_contents(self::argument($command, 3).'/'.$file, $content);
            }

            return self::ok($command, "Installing quire/skeleton (v0.1.1)\n");
        };
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
        $project = $this->name->value;
        $link = 'quire-link-'.$project;
        // Its addresses, as SiteLinks gave them
        $subnet = (string) (new SiteNetworks($this->connection, $this->schema)->find($this->name)->project ?? '10.72.0.32/27');
        $networks = ['default' => ['name' => $project.'_default', 'ipam' => ['config' => [['subnet' => $subnet]]]], $link => ['name' => $link, 'external' => true]];
        $php = ['image' => 'quire-sites/'.$project.'/php-prod', 'build' => $build, 'cap_drop' => ['ALL'], 'security_opt' => ['no-new-privileges:true'], 'mem_limit' => '536870912', 'pids_limit' => 256, 'networks' => ['default' => null, $link => ['aliases' => ['jane.example.quire.internal', 'www.jane.example.quire.internal']]], 'volumes' => [['type' => 'volume', 'source' => 'storage_data', 'target' => '/app/storage']]];

        if ($this->ownDatabase) {
            return [
                'name' => $project,
                'networks' => $networks,
                'services' => [
                    'php' => $php,
                    'worker' => ['image' => 'quire-sites/'.$project.'/php-prod', 'build' => $build, 'cap_drop' => ['ALL'], 'security_opt' => ['no-new-privileges:true'], 'mem_limit' => '536870912', 'pids_limit' => 256, 'networks' => ['default' => null]],
                    'database' => ['image' => 'postgres:18-alpine', 'cap_drop' => ['ALL'], 'security_opt' => ['no-new-privileges:true'], 'mem_limit' => '536870912', 'pids_limit' => 256, 'networks' => ['default' => null], 'volumes' => [['type' => 'volume', 'source' => 'database_data', 'target' => '/var/lib/postgresql']]],
                ],
                'volumes' => ['storage_data' => ['name' => $project.'_storage_data'], 'database_data' => ['name' => $project.'_database_data']],
            ];
        }

        return [
            'name' => $project,
            'networks' => $networks,
            'services' => [
                'php' => $php,
                'worker' => ['image' => 'quire-sites/'.$project.'/php-prod', 'build' => $build, 'cap_drop' => ['ALL'], 'security_opt' => ['no-new-privileges:true'], 'mem_limit' => '536870912', 'pids_limit' => 256, 'networks' => ['default' => null, $link => null]],
            ],
            'volumes' => ['storage_data' => ['name' => $project.'_storage_data']],
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
