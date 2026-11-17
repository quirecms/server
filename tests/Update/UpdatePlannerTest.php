<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\TestCase;
use Quire\Server\Docker\SkeletonInstaller;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Update\Composer;
use Quire\Server\Update\SkeletonFiles;
use Quire\Server\Update\UpdatePlanner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Working out an update, with Composer scripted: on a copy, never running any package's code.
 */
final class UpdatePlannerTest extends TestCase
{
    private string $root;
    private SiteName $name;
    private ServerPaths $paths;
    private ScriptedRunner $runner;

    /** @var array<string, string> What composer update resolves to */
    private array $resolved = ['quire/core' => 'v0.1.1', 'quire/admin' => 'v0.1.1', 'symfony/yaml' => 'v8.1.9'];

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-planner-'.bin2hex(random_bytes(6));
        $this->paths = new ServerPaths($this->root);
        $this->name = new SiteName('jane');
        $site = $this->paths->site($this->name);
        new Filesystem()->dumpFile($site.'/composer.json', (string) json_encode([
            'require' => ['php' => '>=8.5', 'quire/admin' => '^0.1', 'quire/core' => '^0.1', 'symfony/yaml' => '8.1.*'],
            'extra' => ['symfony' => ['require' => '8.1.*']],
        ], \JSON_PRETTY_PRINT));
        new Filesystem()->dumpFile($site.'/composer.lock', LockTest::lock(['quire/admin' => 'v0.1.0', 'quire/core' => 'v0.1.0', 'symfony/yaml' => 'v8.1.0', 'symfony/polyfill-mbstring' => 'v1.33.0'])->content);
        new Filesystem()->dumpFile($site.'/Dockerfile', 'FROM 0.1.0');

        $this->runner = new ScriptedRunner([
            'composer update' => function (array $command, ?string $directory): \Quire\Server\Process\ProcessResult {
                file_put_contents($directory.'/composer.lock', LockTest::lock($this->resolved)->content);

                return ScriptedRunner::ok($command);
            },
            'composer audit' => static fn (array $command, ?string $directory): \Quire\Server\Process\ProcessResult => str_contains((string) file_get_contents($directory.'/composer.lock'), 'v8.1.0')
                ? new \Quire\Server\Process\ProcessResult($command, 1, (string) json_encode(['advisories' => ['symfony/yaml' => [['title' => 'YAML bomb', 'cve' => 'CVE-2026-0001', 'link' => 'https://example.com', 'severity' => 'high']]]]), '')
                : ScriptedRunner::ok($command, '{"advisories": []}'),
            'composer create-project' => static function (array $command): \Quire\Server\Process\ProcessResult {
                new Filesystem()->dumpFile($command[3].'/Dockerfile', 'FROM '.$command[4]);

                return ScriptedRunner::ok($command);
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testAPlanWithinItsConstraints(): void
    {
        $plan = $this->planner()->plan($this->name);

        self::assertSame([
            ['package' => 'quire/admin', 'from' => 'v0.1.0', 'to' => 'v0.1.1'],
            ['package' => 'quire/core', 'from' => 'v0.1.0', 'to' => 'v0.1.1'],
            ['package' => 'symfony/polyfill-mbstring', 'from' => 'v1.33.0', 'to' => null],
            ['package' => 'symfony/yaml', 'from' => 'v8.1.0', 'to' => 'v8.1.9'],
        ], $plan->packages);
        self::assertSame(['symfony/yaml'], array_keys($plan->fixedAdvisories()));
        self::assertSame([], $plan->advisoriesAfter);
        // The new Quire's skeleton, compared with the one the site came from.
        self::assertSame([['path' => 'Dockerfile', 'action' => SkeletonFiles::REPLACE]], $plan->skeletonFiles);
        self::assertTrue($plan->changesAnything());

        $update = $this->command('composer update');
        foreach (['--no-install', '--no-scripts', '--no-plugins', '--with-all-dependencies', '--no-interaction'] as $flag) {
            self::assertContains($flag, $update, 'No package\'s code runs: '.$flag);
        }
        // Symfony stays on its minor version, as Flex would keep it (Flex doesn't run here).
        self::assertContains('--with=symfony/yaml:8.1.*', $update);
        self::assertNotContains('--with=symfony/polyfill-mbstring:8.1.*', $update, 'Packages on their own versions aren\'t pinned.');
        self::assertFalse($this->runner->runs[1]['env']['COMPOSER_AUTH'], 'Nothing inherited.');
        self::assertFalse($this->runner->runs[1]['env']['COMPOSER_NO_BLOCKING'], 'Versions with known vulnerabilities stay refused.');
        self::assertNotContains('--no-blocking', $update);
        self::assertNotContains('--no-progress', $this->command('composer audit'), 'An option audit doesn\'t have.');
        self::assertFalse(str_starts_with((string) $this->runner->runs[1]['directory'], $this->paths->site($this->name)), 'On a copy.');
        self::assertSame(['--no-install', '--no-scripts', '--no-plugins'], \array_slice($this->command('composer create-project'), 5, 3));

        // The site's own files untouched.
        self::assertStringContainsString('v0.1.0', (string) file_get_contents($this->paths->site($this->name).'/composer.lock'));
        $plan->discard();
        self::assertDirectoryDoesNotExist($plan->workspace);
    }

    public function testOnlyThePackagesNamed(): void
    {
        // The security policy: the vulnerable package, and what it needs
        $this->planner()->plan($this->name, only: ['symfony/yaml']);
        $update = $this->command('composer update');

        $at = array_search('update', $update, true);
        self::assertIsInt($at);
        self::assertSame(['update', 'symfony/yaml', '--with-all-dependencies'], \array_slice($update, $at, 3));
    }

    public function testThirdPartyPackagesDontMoveInAnUpdate(): void
    {
        // G1: added with "site:packages … --third-party", held in every update, automatic or not
        $site = $this->paths->site($this->name);
        new Filesystem()->dumpFile($site.'/composer.json', (string) json_encode([
            'require' => ['php' => '>=8.5', 'acme/reviews' => '^1.2', 'quire/core' => '^0.1', 'symfony/yaml' => '8.1.*'],
            'extra' => ['quire-server' => ['packages' => ['acme/reviews' => ['third-party' => true]]]],
        ]));
        new Filesystem()->dumpFile($site.'/composer.lock', LockTest::packages([
            LockTest::package('acme/reviews', '1.2.0', 'quire-extension', ['quire/core' => '^0.1', 'acme/reviews-core' => '^1.0', 'symfony/yaml' => '^8.1']),
            LockTest::package('acme/reviews-core', '1.0.4'),
            LockTest::package('quire/core', 'v0.1.0', 'symfony-bundle', ['symfony/yaml' => '^8.1']),
            LockTest::package('symfony/yaml', 'v8.1.0'),
        ])->content);

        foreach ([[], ['acme/reviews']] as $only) {
            $plan = $this->planner()->plan($this->name, only: $only);
            $update = $this->command('composer update');

            self::assertContains('--with=acme/reviews:1.2.0', $update, 'Held at its version, even when it\'s the vulnerable one.');
            self::assertContains('--with=acme/reviews-core:1.0.4', $update, 'And what only it needs.');
            self::assertNotContains('--with=symfony/yaml:v8.1.0', $update, 'Not what Quire needs too.');
            self::assertSame(['acme/reviews' => '1.2.0', 'acme/reviews-core' => '1.0.4'], $plan->held);
            $plan->discard();
            $this->runner->runs = [];
        }
    }

    public function testAHeldReleaseRepublishedStopsTheUpdate(): void
    {
        // G3′: --with holds its version, not its code; a moved tag gives other code at that version.
        $site = $this->paths->site($this->name);
        new Filesystem()->dumpFile($site.'/composer.json', (string) json_encode([
            'require' => ['acme/reviews' => '^1.2', 'quire/core' => '^0.1'],
            'extra' => ['quire-server' => ['packages' => ['acme/reviews' => ['third-party' => true]]]],
        ]));
        $locked = [LockTest::package('acme/reviews', '1.2.0', 'quire-extension'), LockTest::package('quire/core', 'v0.1.0', 'symfony-bundle')];
        new Filesystem()->dumpFile($site.'/composer.lock', LockTest::packages($locked)->content);
        $moved = [LockTest::package('acme/reviews', '1.2.0', 'quire-extension', commit: str_repeat('d', 40)), LockTest::package('quire/core', 'v0.1.1', 'symfony-bundle')];
        $this->runner = new ScriptedRunner([
            ...$this->runner->script,
            'composer update' => static function (array $command, ?string $directory) use ($moved): \Quire\Server\Process\ProcessResult {
                file_put_contents($directory.'/composer.lock', LockTest::packages($moved)->content);

                return ScriptedRunner::ok($command);
            },
        ]);

        $this->expectExceptionMessageIsOrContains('acme/reviews 1.2.0 was re-published as other code (commit '.str_repeat('d', 40).', was '.LockTest::commit('acme/reviews', '1.2.0').'): a third party\'s release changed under its version, so nothing moves until you look at it ("site:packages jane update acme/reviews --third-party" shows it).');
        $this->planner()->plan($this->name);
    }

    public function testThePlanHoldsForTheFilesItWasMadeFrom(): void
    {
        // Another update applied while this one is worked out: the plan isn't for the files it left.
        $site = $this->paths->site($this->name);
        $before = UpdatePlanner::siteFiles($site);
        $update = $this->runner->script['composer update'];
        $script = static function (array $command, ?string $directory) use ($update, $site): \Quire\Server\Process\ProcessResult {
            file_put_contents($site.'/composer.lock', LockTest::lock(['quire/core' => 'v0.1.5'])->content);

            return $update(array_values(array_filter($command, \is_string(...))), $directory, null);
        };
        $this->runner->script['composer update'] = $script;

        $plan = $this->planner()->plan($this->name);

        self::assertSame($before, $plan->siteFiles);
        self::assertNotSame($plan->siteFiles, UpdatePlanner::siteFiles($site), 'So SiteUpdater refuses it.');
        $plan->discard();
    }

    public function testOnlyPackageNamesAreNamed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->planner()->plan($this->name, only: ['--no-blocking']);
    }

    public function testMovingToAnotherRelease(): void
    {
        $this->resolved = ['quire/core' => 'v0.2.0', 'quire/admin' => 'v0.2.0', 'symfony/yaml' => 'v8.1.9'];
        $plan = $this->planner()->plan($this->name, '0.2.0');

        $update = $this->command('composer update');
        self::assertContains('--with=quire/admin:0.2.0', $update);
        self::assertContains('--with=quire/core:0.2.0', $update);
        $json = json_decode($plan->composerJson, true);
        self::assertIsArray($json);
        self::assertSame(['php' => '>=8.5', 'quire/admin' => '^0.2.0', 'quire/core' => '^0.2.0', 'symfony/yaml' => '8.1.*'], $json['require']);
        self::assertSame('v0.2.0', $plan->quireTo());
        $plan->discard();
    }

    public function testUpToDate(): void
    {
        $this->resolved = ['quire/admin' => 'v0.1.0', 'quire/core' => 'v0.1.0', 'symfony/yaml' => 'v8.1.0', 'symfony/polyfill-mbstring' => 'v1.33.0'];
        $plan = $this->planner()->plan($this->name);

        self::assertFalse($plan->changesAnything());
        self::assertNotContains('composer create-project', $this->runner->keys(), 'Same Quire: no skeleton to compare.');
        $plan->discard();
    }

    public function testOnlyAReleaseCanBeMovedTo(): void
    {
        $this->expectExceptionMessageIsOrContains('isn\'t a Quire release');
        $this->planner()->plan($this->name, '0.2; rm -rf /');
    }

    private function planner(): UpdatePlanner
    {
        return new UpdatePlanner(new Composer($this->runner), new SkeletonInstaller($this->runner), $this->paths);
    }

    /**
     * @return list<string>
     */
    private function command(string $key): array
    {
        foreach ($this->runner->runs as $run) {
            if (ScriptedRunner::key($run['command']) === $key) {
                return $run['command'];
            }
        }

        self::fail($key.' wasn\'t run.');
    }
}
