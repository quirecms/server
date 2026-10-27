<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Operation;

use Quire\Server\Database\SiteDatabases;
use Quire\Server\Docker\Compose;
use Quire\Server\Docker\ComposePolicy;
use Quire\Server\Docker\PolicyViolation;
use Quire\Server\Docker\SkeletonInstaller;
use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Operation\SiteRequest;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;
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
        self::assertSame(['config', 'build', 'config', 'up', 'exec', 'exec'], array_map(static fn (array $command): string => $command[8], \array_slice($this->commands, 1)));

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
            public function run(array $command, array $env = [], ?string $directory = null, float $timeout = 600, ?\Closure $output = null, ?string $input = null): ProcessResult
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
            new Compose($runner, new ComposePolicy(), 'tcp://docker-proxy:2375'),
            $this->frontDoor,
            $this->paths,
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
            self::assertSame(['--project-directory', $this->paths->site($this->name), '--env-file', $this->paths->site($this->name).'/prod.env', '--project-name', $this->name->value], \array_slice($command, 2, 6));
        }

        $key = 'docker' === $command[0] ? 'docker compose '.$command[8] : $command[0].' '.$command[1];

        return ($this->script[$key] ?? throw new \LogicException('Unexpected command: '.implode(' ', $command)))($command);
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
