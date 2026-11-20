<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use Quire\Server\Git\Repository;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Update\Composer;
use Quire\Server\Update\PackagePlan;
use Quire\Server\Update\SiteExtensions;
use Quire\Server\Update\SitePackages;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Adding, moving and removing a managed site's packages (Gap B), with Composer scripted: what each
 * check refuses, on a copy of the site's composer files, never the site's own.
 */
final class SitePackagesTest extends RegistryTestCase
{
    private string $root;
    private SiteName $name;
    private ServerPaths $paths;
    private ScriptedRunner $runner;
    private SiteGit $repositories;

    /** @var list<array<string, mixed>> What Composer resolves the lock to (update, remove) */
    private array $resolved;

    /** @var array<string, string> The newest release of each package, for "composer require" without a constraint */
    private array $newest = [];

    /** @var string|null What Composer says when it can't resolve (null: it resolves) */
    private ?string $unresolvable = null;

    /** @var list<string> Packages with a known vulnerability, wherever they are */
    private array $vulnerable = [];

    /** @var array{extensions: list<array{id: string, package: string, status: string}>, theme: ?string} */
    private array $report = ['extensions' => [], 'theme' => 'quire-theme-default'];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-packages-test-'.bin2hex(random_bytes(6));
        $this->paths = new ServerPaths($this->root);
        $this->name = new SiteName('jane');
        $this->repositories = new SiteGit($this->connection, $this->schema);
        $site = $this->paths->site($this->name);
        new Filesystem()->dumpFile($site.'/composer.json', (string) json_encode([
            'require' => ['php' => '>=8.5.11', 'quire/admin' => '^0.3', 'quire/core' => '^0.3', 'quire/theme-default' => '^0.3', 'symfony/yaml' => '8.1.*'],
            'config' => ['allow-plugins' => ['symfony/flex' => true]],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        $this->resolved = $this->site();
        new Filesystem()->dumpFile($site.'/composer.lock', LockTest::packages($this->resolved)->content);

        $this->runner = new ScriptedRunner([
            'composer require' => function (array $command, ?string $directory): ProcessResult {
                $parts = explode(':', $command[2], 2);
                [$package, $constraint] = [$parts[0], $parts[1] ?? null];
                $json = json_decode((string) file_get_contents($directory.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
                \assert(\is_array($json) && \is_array($json['require']));
                $json['require'][$package] = $constraint ?? '^'.($this->newest[$package] ?? '1.0.0');
                file_put_contents($directory.'/composer.json', json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

                return ScriptedRunner::ok($command);
            },
            'composer update' => function (array $command, ?string $directory): ProcessResult {
                if (null !== $this->unresolvable) {
                    return new ProcessResult($command, 2, '', $this->unresolvable);
                }

                file_put_contents($directory.'/composer.lock', LockTest::packages($this->resolved)->content);

                return ScriptedRunner::ok($command);
            },
            'composer remove' => function (array $command, ?string $directory): ProcessResult {
                $json = json_decode((string) file_get_contents($directory.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
                \assert(\is_array($json) && \is_array($json['require']));
                unset($json['require'][$command[2]]);
                file_put_contents($directory.'/composer.json', json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
                file_put_contents($directory.'/composer.lock', LockTest::packages($this->resolved)->content);

                return ScriptedRunner::ok($command);
            },
            'composer why' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, "beta/gallery 0.4.0 requires acme/reviews (^1.2)\n"),
            'composer audit' => function (array $command, ?string $directory): ProcessResult {
                $lock = (string) file_get_contents($directory.'/composer.lock');
                $advisories = [];

                foreach ($this->vulnerable as $package) {
                    if (str_contains($lock, '"'.$package.'"')) {
                        $advisories[$package] = [['title' => 'Stored XSS in '.$package, 'cve' => 'CVE-2026-4242', 'link' => null, 'severity' => 'high']];
                    }
                }

                return new ProcessResult($command, [] === $advisories ? 0 : 1, (string) json_encode(['advisories' => (object) $advisories]), '');
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testAddingOneOfQuiresExtensions(): void
    {
        $this->resolved = [...$this->site(), $this->analytics(), $this->privacy()];
        $plan = $this->packages()->add($this->name, 'quire/analytics', false);

        self::assertSame([PackagePlan::ADD, 'quire/analytics', false, 'v0.3.1'], [$plan->action, $plan->package, $plan->thirdParty, $plan->version]);
        self::assertSame([
            ['package' => 'quire/analytics', 'from' => null, 'to' => 'v0.3.1'],
            ['package' => 'quire/privacy', 'from' => null, 'to' => 'v0.3.1'],
        ], $plan->plan->packages, 'It, and what it needs.');
        self::assertSame(['quire/analytics', 'quire/privacy'], array_column($plan->arrived, 'package'));
        self::assertTrue($plan->arrived[0]['quire']);

        // Recorded in composer.json, which goes with the lock (backups, rollbacks, undo)
        $json = json_decode($plan->plan->composerJson, true);
        self::assertIsArray($json);
        self::assertSame(['packages' => ['quire/analytics' => ['third-party' => false]]], $this->at($json, 'extra', 'quire-server'));
        self::assertSame('^0.3.1', $this->at($json, 'require', 'quire/analytics'), '"^" and the release that fits the site, as Composer writes one.');
        self::assertSame([], $plan->plan->held, 'Quire\'s follow the site\'s updates.');
        self::assertSame(['site.packages', ['action' => 'add', 'package' => 'quire/analytics', 'from' => null, 'to' => 'v0.3.1', 'third_party' => false, 'packages' => 2]], [$plan->plan->operation, $plan->plan->details]);

        // Recorded before Composer resolves (the lock's content-hash covers "extra"); then only it moves.
        // Any release, resolved with the site; then "^" and the release it got, and the lock again for its hash
        self::assertSame(['composer audit', 'composer require', 'composer update', 'composer require', 'composer update', 'composer audit'], $this->runner->keys());
        $require = $this->command('composer require');
        self::assertSame('quire/analytics:*', $require[2]);
        self::assertSame('quire/analytics:^0.3.1', $this->runner->runs[3]['command'][2]);
        self::assertContains('--no-update', $require);
        $update = $this->command('composer update');
        self::assertSame(['update', 'quire/analytics', '--with-dependencies'], \array_slice($update, 1, 3), 'Composer\'s minimal changes: nothing the site requires moves.');

        foreach (['--no-scripts', '--no-plugins'] as $flag) {
            self::assertContains($flag, $require, 'No package\'s code runs here: '.$flag);
        }

        foreach (['--no-install', '--no-scripts', '--no-plugins'] as $flag) {
            self::assertContains($flag, $update, 'No package\'s code runs here: '.$flag);
        }

        self::assertFalse(str_starts_with((string) $this->runner->runs[1]['directory'], $this->paths->site($this->name)), 'On a copy.');
        self::assertStringNotContainsString('quire/analytics', (string) file_get_contents($this->paths->site($this->name).'/composer.json'), 'The site\'s own files untouched.');
        $plan->discard();
        self::assertDirectoryDoesNotExist($plan->plan->workspace);
    }

    public function testAThirdPartysPackageOnlyWhenAsked(): void
    {
        $this->expectExceptionMessageIsOrContains('acme/reviews isn\'t Quire\'s: its code would run in the site, with the site\'s database and files. Add it only from someone you trust, with --third-party.');

        try {
            $this->packages()->add($this->name, 'acme/reviews', false);
        } finally {
            self::assertSame([], $this->runner->keys(), 'Refused before anything runs.');
        }
    }

    public function testAddingAThirdPartysPackage(): void
    {
        $this->resolved = [...$this->site(), ...$this->reviews()];
        $this->newest = ['acme/reviews' => '1.2.0'];
        $plan = $this->packages()->add($this->name, 'acme/reviews', true);

        self::assertTrue($plan->thirdParty);
        $reviews = $plan->arrived[0];
        // G3: shown with the commit its download names, which is what anchors it
        self::assertSame(['acme/reviews', '1.2.0', 'quire-extension', false, 'https://github.com/acme/reviews.git', 'https://api.github.com/repos/acme/reviews/zipball/'.LockTest::commit('acme/reviews', '1.2.0'), LockTest::commit('acme/reviews', '1.2.0'), null], array_values($reviews));
        // G1: from now on, held in every update
        self::assertSame(['acme/reviews' => '1.2.0', 'acme/reviews-core' => '1.0.4'], $plan->plan->held);
        $json = json_decode($plan->plan->composerJson, true);
        self::assertIsArray($json);
        self::assertSame(['acme/reviews' => ['third-party' => true]], $this->at($json, 'extra', 'quire-server', 'packages'));
        self::assertSame('^1.2.0', $this->at($json, 'require', 'acme/reviews'));
        $plan->discard();
    }

    public function testTheReleaseThatFitsTheSiteNotTheNewest(): void
    {
        // Packagist's newest needs another Quire than the site's; Composer resolves the one that fits
        $this->newest = ['quire/analytics' => '0.4.0'];
        $this->resolved = [...$this->site(), $this->analytics(), $this->privacy()];
        $plan = $this->packages()->add($this->name, 'quire/analytics', false);

        $json = json_decode($plan->plan->composerJson, true);
        self::assertIsArray($json);
        self::assertSame('^0.3.1', $this->at($json, 'require', 'quire/analytics'));
        self::assertSame('v0.3.1', $plan->version);
        $plan->discard();
    }

    public function testOnlyAStableReleaseWithoutAConstraint(): void
    {
        $this->resolved = [...$this->site(), LockTest::package('acme/reviews', '1.3.0-beta1', 'quire-extension', ['quire/core' => '^0.3'])];
        $this->expectExceptionMessageIsOrContains('acme/reviews 1.3.0-beta1 isn\'t a stable release: add it with a constraint ("acme/reviews:^1.2").');
        $this->packages()->add($this->name, 'acme/reviews', true);
    }

    public function testAReleaseThatNeedsANewerQuireNamesWhatToDo(): void
    {
        // Composer 2.10's words, on a site on Quire 0.3 (the one-line form, and the form for several releases)
        $this->unresolvable = <<<'TEXT'
            Your requirements could not be resolved to an installable set of packages.

              Problem 1
                - Root composer.json requires quire/analytics ^0.4 -> satisfiable by quire/analytics[v0.4.0].
                - quire/analytics v0.4.0 requires quire/core ^0.4 -> found quire/core[v0.4.0] but it conflicts with your root composer.json require (^0.3).
                - quire/privacy[v0.4.0, v0.4.1] require symfony/yaml ^8.2 -> found symfony/yaml[v8.2.0] but the package is fixed to v8.1.0 (lock file version) by a partial update and that version does not match.
            TEXT;

        $this->expectExceptionMessageIsOrContains('It needs newer versions than "jane" has: quire/analytics 0.4.0 needs quire/core ^0.4 (the site has 0.3.1); quire/privacy 0.4.1 needs symfony/yaml ^8.2 (the site has 8.1.0). Update the site first ("site:update jane"), then try again; or, without a version, the newest release that fits the site is added.');
        $this->packages()->add($this->name, 'quire/analytics:^0.4', false);
    }

    public function testAnotherReasonComposerCantResolveIsComposers(): void
    {
        $this->unresolvable = "Your requirements could not be resolved to an installable set of packages.\n\n  Problem 1\n    - Root composer.json requires acme/nothing ^9.0, it could not be found in any version.\n";

        $this->expectExceptionMessageIsOrContains('Composer couldn\'t work out the versions to install: Root composer.json requires acme/nothing ^9.0, it could not be found in any version');
        $this->packages()->add($this->name, 'acme/nothing:^9.0', true);
    }

    public function testWithAConstraint(): void
    {
        $this->resolved = [...$this->site(), ...$this->reviews()];
        $this->packages()->add($this->name, 'acme/reviews:^1.2', true)->discard();

        self::assertSame('acme/reviews:^1.2', $this->command('composer require')[2]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notReleases(): iterable
    {
        yield 'a branch' => ['acme/reviews:dev-main', 'isn\'t a version constraint'];
        yield 'a commit' => ['acme/reviews:1.2.0#abc123', 'isn\'t a version constraint'];
        yield 'a stability flag' => ['acme/reviews:^1.2@beta', 'isn\'t a version constraint'];
        yield 'an option' => ['--no-plugins', 'isn\'t a package'];
        yield 'no vendor' => ['reviews', 'isn\'t a package'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notReleases')]
    public function testOnlyReleasesOfPackages(string $requirement, string $refused): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($refused);

        $this->packages()->add($this->name, $requirement, true);
    }

    public function testOnlyQuireExtensionsAndThemes(): void
    {
        $this->resolved = [...$this->site(), LockTest::package('acme/helpers', '2.0.0')];

        $this->expectExceptionMessageIsOrContains('acme/helpers is a "library", not a Quire extension or theme: only those are added');
        $this->packages()->add($this->name, 'acme/helpers', true);
    }

    public function testNothingElseMoves(): void
    {
        $site = $this->site();
        $site[3]['version'] = 'v8.1.9';
        $this->resolved = [...$site, ...$this->reviews()];

        $this->expectExceptionMessageIsOrContains('It would move packages the site has: symfony/yaml v8.1.0 → v8.1.9. Nothing else moves here: update the site first ("site:update"), then try again.');
        $this->packages()->add($this->name, 'acme/reviews', true);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function standIns(): iterable
    {
        yield 'it replaces one of Quire\'s' => [LockTest::package('acme/reviews', '1.2.0', 'quire-extension', replace: ['quire/core' => '*']), 'acme/reviews replaces quire/core (it would stand in for it)'];
        yield 'it provides one of Quire\'s the site doesn\'t have' => [LockTest::package('acme/reviews', '1.2.0', 'quire-extension', provide: ['quire/analytics' => '0.3.1']), 'acme/reviews provides quire/analytics'];
        yield 'it replaces one the site has' => [LockTest::package('acme/reviews', '1.2.0', 'quire-extension', replace: ['symfony/yaml' => '*']), 'acme/reviews replaces symfony/yaml'];
        yield 'what it brings provides one the site has' => [LockTest::package('acme/reviews-core', '1.0.4', provide: ['psr/log' => '3.0.2']), 'acme/reviews-core provides psr/log'];
        yield 'what it brings is a Composer plugin' => [LockTest::package('acme/reviews-core', '1.0.4', 'composer-plugin'), 'acme/reviews-core is a Composer plugin'];
        yield 'no commit to know its code by' => [['name' => 'acme/reviews-core', 'version' => '1.0.4', 'dist' => ['type' => 'zip', 'url' => 'https://example.com/core.zip', 'shasum' => '']], 'acme/reviews-core\'s download names no commit'];
        yield 'not over HTTPS' => [['name' => 'acme/reviews-core', 'version' => '1.0.4', 'dist' => ['type' => 'zip', 'url' => 'http://example.com/core.zip', 'reference' => 'abc']], 'acme/reviews-core isn\'t a download over HTTPS'];
        yield 'no download at all' => [['name' => 'acme/reviews-core', 'version' => '1.0.4', 'source' => ['type' => 'git', 'url' => 'https://example.com/core.git', 'reference' => 'abc']], 'acme/reviews-core isn\'t a download over HTTPS'];
    }

    /**
     * G2 (nothing stands in for a package the site has or for Quire's), no plugins, G3.
     *
     * @param array<string, mixed> $arrival
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('standIns')]
    public function testWhatArrivesIsChecked(array $arrival, string $refused): void
    {
        $arrivals = array_map(static fn (array $package): array => $package['name'] === $arrival['name'] ? $arrival : $package, $this->reviews());
        $this->resolved = [...$this->site(), ...$arrivals];
        $workspaces = $this->workspaces();

        try {
            $this->packages()->add($this->name, 'acme/reviews', true);
            self::fail('Added.');
        } catch (\DomainException $e) {
            self::assertStringContainsString($refused, $e->getMessage());
        }

        self::assertSame($workspaces, $this->workspaces(), 'Its copy is gone.');
    }

    public function testQuiresOwnAreCheckedWhenTheSiteIsBuilt(): void
    {
        // D-38 checks quire/* against the signed release feed at the build: no download check here.
        $analytics = $this->analytics();
        $analytics['dist'] = ['type' => 'zip', 'url' => 'https://api.github.com/repos/quirecms/analytics/zipball/x'];
        $this->resolved = [...$this->site(), $analytics, $this->privacy()];

        $this->packages()->add($this->name, 'quire/analytics', false)->discard();
        $this->addToAssertionCount(1);
    }

    public function testNoNewKnownVulnerability(): void
    {
        $this->resolved = [...$this->site(), ...$this->reviews()];
        $this->vulnerable = ['acme/reviews-core'];

        $this->expectExceptionMessageIsOrContains('It would bring known vulnerabilities: acme/reviews-core: Stored XSS in acme/reviews-core (CVE-2026-4242).');
        $this->packages()->add($this->name, 'acme/reviews', true);
    }

    public function testAKnownVulnerabilityTheSiteHasAlreadyIsntNew(): void
    {
        $this->resolved = [...$this->site(), ...$this->reviews()];
        $this->vulnerable = ['symfony/yaml'];

        $this->packages()->add($this->name, 'acme/reviews', true)->discard();
        $this->addToAssertionCount(1);
    }

    public function testNotOneItHas(): void
    {
        $this->expectExceptionMessageIsOrContains('"jane" has quire/admin already.');
        $this->packages()->add($this->name, 'quire/admin', false);
    }

    public function testNotOneItHasAsADependency(): void
    {
        file_put_contents($this->paths->site($this->name).'/composer.lock', LockTest::packages([...$this->site(), $this->analytics(), $this->privacy()])->content);

        $this->expectExceptionMessageIsOrContains('"jane" has quire/privacy v0.3.1 already, because another package needs it');
        $this->packages()->add($this->name, 'quire/privacy', false);
    }

    public function testThePlanHoldsForTheFilesItWasMadeFrom(): void
    {
        // G4: a change to the site's files while it's worked out (another update applied meanwhile)
        $this->resolved = [...$this->site(), ...$this->reviews()];
        $site = $this->paths->site($this->name);
        $before = \Quire\Server\Update\UpdatePlanner::siteFiles($site);
        $script = function (array $command, ?string $directory) use ($site): ProcessResult {
            file_put_contents($site.'/composer.lock', LockTest::packages([...$this->site(), LockTest::package('psr/clock', '1.0.0')])->content);
            file_put_contents($directory.'/composer.lock', LockTest::packages($this->resolved)->content);

            return ScriptedRunner::ok(array_values(array_filter($command, \is_string(...))));
        };
        $this->runner->script['composer update'] = $script;

        $plan = $this->packages()->add($this->name, 'acme/reviews', true);

        self::assertSame($before, $plan->plan->siteFiles, 'The files it was made from, not those at its end: the apply refuses them.');
        self::assertNotSame($plan->plan->siteFiles, \Quire\Server\Update\UpdatePlanner::siteFiles($site));
        $plan->discard();
    }

    public function testNotInGitMode(): void
    {
        new SiteRegistry($this->connection, $this->schema)->add($this->name, [new Domain('jane.example')], Site::SHARED_DATABASE, 'jane');
        $this->repositories->follow($this->name, new Repository('https://github.com/jane/site.git', 'main'), false);

        $this->expectExceptionMessageIsOrContains('"jane" follows a Git repository: add its packages there');
        $this->packages()->add($this->name, 'quire/analytics', false);
    }

    public function testFromPackagistOnly(): void
    {
        $site = $this->paths->site($this->name);
        $json = json_decode((string) file_get_contents($site.'/composer.json'), true);
        \assert(\is_array($json));
        $json['repositories'] = [['type' => 'vcs', 'url' => 'https://github.com/acme/reviews']];
        file_put_contents($site.'/composer.json', json_encode($json));

        $this->expectExceptionMessageIsOrContains('names repositories of its own: packages come from Packagist only here');
        $this->packages()->add($this->name, 'acme/reviews', true);
    }

    public function testListingWhatItHas(): void
    {
        $this->addReviews();

        self::assertSame([
            ['package' => 'acme/reviews', 'constraint' => '^1.2', 'version' => '1.2.0', 'type' => 'quire-extension', 'added' => 'third-party'],
            ['package' => 'quire/admin', 'constraint' => '^0.3', 'version' => 'v0.3.1', 'type' => 'quire-extension', 'added' => null],
            ['package' => 'quire/core', 'constraint' => '^0.3', 'version' => 'v0.3.1', 'type' => 'symfony-bundle', 'added' => null],
            ['package' => 'quire/theme-default', 'constraint' => '^0.3', 'version' => 'v0.3.1', 'type' => 'quire-theme', 'added' => null],
            ['package' => 'symfony/yaml', 'constraint' => '8.1.*', 'version' => 'v8.1.0', 'type' => 'library', 'added' => null],
        ], $this->packages()->list($this->name));
    }

    public function testRemovingOneItAdded(): void
    {
        $this->addReviews();
        $this->report['extensions'] = [['id' => 'acme-reviews', 'package' => 'acme/reviews', 'status' => 'disabled']];
        $this->resolved = $this->site();

        $plan = $this->packages()->remove($this->name, 'acme/reviews');

        self::assertSame([
            ['package' => 'acme/reviews', 'from' => '1.2.0', 'to' => null],
            ['package' => 'acme/reviews-core', 'from' => '1.0.4', 'to' => null],
        ], $plan->plan->packages, 'With what only it needed.');
        $json = json_decode($plan->plan->composerJson, true);
        self::assertIsArray($json);
        self::assertArrayNotHasKey('extra', $json, 'Its record goes with it.');
        self::assertSame([], $plan->plan->held);
        self::assertSame('remove', $this->command('composer remove')[1]);
        $plan->discard();
    }

    /**
     * @return iterable<string, array{list<array{id: string, package: string, status: string}>, ?string, string}>
     */
    public static function running(): iterable
    {
        yield 'enabled' => [[['id' => 'acme-reviews', 'package' => 'acme/reviews', 'status' => 'enabled']], 'quire-theme-default', '"jane" runs acme/reviews ("acme-reviews", enabled): disable it in its admin first'];
        yield 'enabled, failing to start' => [[['id' => 'acme-reviews', 'package' => 'acme/reviews', 'status' => 'broken']], 'quire-theme-default', '("acme-reviews", broken)'];
        yield 'its theme' => [[], 'fancy', 'acme/fancy is "jane"\'s theme: choose another in its admin first'];
        yield 'its theme\'s parent' => [[], 'fancy-child', 'acme/fancy is the parent of "jane"\'s theme (fancy-child)'];
    }

    /**
     * @param list<array{id: string, package: string, status: string}> $extensions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('running')]
    public function testNotWhileTheSiteRunsIt(array $extensions, ?string $theme, string $refused): void
    {
        $this->addReviews();
        $this->report = ['extensions' => $extensions, 'theme' => $theme];
        $removed = str_contains($refused, 'acme/fancy') ? 'acme/fancy' : 'acme/reviews';

        if ('acme/fancy' === $removed) {
            $this->addTheme();
        }

        try {
            $this->packages()->remove($this->name, $removed);
            self::fail('Removed.');
        } catch (\DomainException $e) {
            self::assertStringContainsString($refused, $e->getMessage());
        }

        self::assertNotContains('composer remove', $this->runner->keys());
    }

    public function testNorWhatGoesWithIt(): void
    {
        // quire/analytics needs quire/privacy, which the site still runs: it would go with it.
        $site = $this->paths->site($this->name);
        $json = json_decode((string) file_get_contents($site.'/composer.json'), true);
        \assert(\is_array($json) && \is_array($json['require']));
        $json['require']['quire/analytics'] = '^0.3';
        $json['extra'] = ['quire-server' => ['packages' => ['quire/analytics' => ['third-party' => false]]]];
        file_put_contents($site.'/composer.json', json_encode($json));
        file_put_contents($site.'/composer.lock', LockTest::packages([...$this->site(), $this->analytics(), $this->privacy()])->content);
        $this->report['extensions'] = [['id' => 'quire-analytics', 'package' => 'quire/analytics', 'status' => 'disabled'], ['id' => 'quire-privacy', 'package' => 'quire/privacy', 'status' => 'enabled']];
        $this->resolved = $this->site();

        $this->expectExceptionMessageIsOrContains('"jane" runs quire/privacy ("quire-privacy", enabled): disable it in its admin first');
        $this->packages()->remove($this->name, 'quire/analytics');
    }

    public function testRemovingMovesNothing(): void
    {
        $this->addReviews();
        $site = $this->site();
        $site[3]['version'] = 'v8.1.9';
        $this->resolved = $site;

        $this->expectExceptionMessageIsOrContains('It would move packages the site has: symfony/yaml v8.1.0 → v8.1.9.');
        $this->packages()->remove($this->name, 'acme/reviews');
    }

    public function testNotWhatTheSiteCameWith(): void
    {
        $this->expectExceptionMessageIsOrContains('quire/theme-default came with the site (from Quire\'s skeleton): it isn\'t removed with "site:packages".');
        $this->packages()->remove($this->name, 'quire/theme-default');
    }

    public function testNotWhileAnotherPackageNeedsIt(): void
    {
        $this->addReviews();
        $this->resolved = [...$this->site(), ...$this->reviews()];

        $this->expectExceptionMessageIsOrContains("Another package needs acme/reviews, so it stays:\nbeta/gallery 0.4.0 requires acme/reviews (^1.2)");
        $this->packages()->remove($this->name, 'acme/reviews');
    }

    public function testMovingAThirdPartysPackage(): void
    {
        $this->addReviews();
        $this->resolved = [...$this->site(), LockTest::package('acme/reviews', '1.3.0', 'quire-extension', ['quire/core' => '^0.3', 'acme/reviews-core' => '^1.1', 'acme/stars' => '^1.0']), LockTest::package('acme/reviews-core', '1.1.0'), LockTest::package('acme/stars', '1.0.0')];

        $plan = $this->packages()->update($this->name, 'acme/reviews', true);

        self::assertSame([
            ['package' => 'acme/reviews', 'from' => '1.2.0', 'to' => '1.3.0'],
            ['package' => 'acme/reviews-core', 'from' => '1.0.4', 'to' => '1.1.0'],
            ['package' => 'acme/stars', 'from' => null, 'to' => '1.0.0'],
        ], $plan->plan->packages, 'It, and what only it needs, move; what it newly needs is shown and checked.');
        self::assertSame(['acme/reviews', 'acme/reviews-core', 'acme/stars'], array_column($plan->arrived, 'package'), 'Every package whose code is new is shown and checked, the moved ones too (G2′).');
        self::assertSame([['1.2.0', LockTest::commit('acme/reviews', '1.3.0')], ['1.0.4', LockTest::commit('acme/reviews-core', '1.1.0')], [null, LockTest::commit('acme/stars', '1.0.0')]], array_map(static fn (array $arrival): array => [$arrival['from'], $arrival['reference']], $plan->arrived));
        self::assertSame(['update', 'acme/reviews', '--with-dependencies'], \array_slice($this->command('composer update'), 1, 3));
        self::assertNotContains('composer require', $this->runner->keys(), 'Within its constraint.');
        self::assertSame(['acme/reviews' => '1.3.0', 'acme/reviews-core' => '1.1.0', 'acme/stars' => '1.0.0'], $plan->plan->held, 'Held again at its new version.');
        $plan->discard();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function movedStandIns(): iterable
    {
        yield 'its new version provides one of Quire\'s' => [LockTest::package('acme/reviews', '1.3.0', 'quire-extension', ['quire/core' => '^0.3', 'acme/reviews-core' => '^1.0'], provide: ['quire/analytics' => '0.3.1']), 'It can\'t be moved: acme/reviews provides quire/analytics (it would stand in for it).'];
        yield 'what it needs, moved, becomes a plugin' => [LockTest::package('acme/reviews-core', '1.1.0', 'composer-plugin'), 'acme/reviews-core is a Composer plugin'];
        yield 'what it needs, moved, has no commit' => [['name' => 'acme/reviews-core', 'version' => '1.1.0', 'dist' => ['type' => 'zip', 'url' => 'https://example.com/core.zip', 'shasum' => '']], 'acme/reviews-core\'s download names no commit'];
    }

    /**
     * G2′: the checks hold for what moves, not only for what arrives.
     *
     * @param array<string, mixed> $moved
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('movedStandIns')]
    public function testWhatMovesIsCheckedToo(array $moved, string $refused): void
    {
        $this->addReviews();
        $packages = 'acme/reviews-core' === ($moved['name'] ?? null)
            ? [LockTest::package('acme/reviews', '1.3.0', 'quire-extension', ['quire/core' => '^0.3', 'acme/reviews-core' => '^1.1']), $moved]
            : [$moved, $this->reviews()[1]];
        $this->resolved = [...$this->site(), ...$packages];

        $this->expectExceptionMessageIsOrContains($refused);
        $this->packages()->update($this->name, 'acme/reviews', true);
    }

    public function testAddingMovesNoReleaseUnderItsVersion(): void
    {
        // G3′: Composer took the site's symfony/yaml v8.1.0 as other code (its tag was moved)
        $site = $this->site();
        $site[3] = LockTest::package('symfony/yaml', 'v8.1.0', commit: str_repeat('e', 40));
        $this->resolved = [...$site, ...$this->reviews()];

        $this->expectExceptionMessageIsOrContains('It would move packages the site has: symfony/yaml v8.1.0 re-published as other code (commit '.str_repeat('e', 40).', was '.LockTest::commit('symfony/yaml', 'v8.1.0').').');
        $this->packages()->add($this->name, 'acme/reviews', true);
    }

    public function testMovingOneShowsItsReleaseRepublished(): void
    {
        $this->addReviews();
        $reviews = $this->reviews();
        $reviews[0] = LockTest::package('acme/reviews', '1.2.0', 'quire-extension', ['quire/core' => '^0.3', 'acme/reviews-core' => '^1.0', 'psr/log' => '^3.0'], commit: str_repeat('f', 40));
        $this->resolved = [...$this->site(), ...$reviews];

        $plan = $this->packages()->update($this->name, 'acme/reviews', true);

        self::assertSame([['acme/reviews', '1.2.0', '1.2.0', str_repeat('f', 40)]], array_map(static fn (array $arrival): array => [$arrival['package'], $arrival['from'], $arrival['version'], $arrival['reference']], $plan->arrived), 'Shown and checked as other code, when the owner moves it.');
        $plan->discard();
    }

    public function testMovingOneHoldsTheOthers(): void
    {
        $this->addReviews(gallery: true);
        $this->resolved = [...$this->site(), ...$this->reviews(), LockTest::package('beta/gallery', '0.5.0', 'quire-extension', ['acme/reviews' => '^1.2'])];

        $this->packages()->update($this->name, 'beta/gallery:^0.5', true)->discard();

        self::assertSame('beta/gallery:^0.5', $this->command('composer require')[2]);
        self::assertContains('--with=acme/reviews:1.2.0', $this->command('composer update'), 'Another third party\'s stays where it is, even when the moving one needs it.');
        self::assertContains('--with=acme/reviews-core:1.0.4', $this->command('composer update'));
    }

    public function testMovingOneMovesNothingElse(): void
    {
        $this->addReviews(gallery: true);
        $reviews = $this->reviews();
        $reviews[0]['version'] = '1.3.0';
        $this->resolved = [...$this->site(), ...$reviews, LockTest::package('beta/gallery', '0.5.0', 'quire-extension', ['acme/reviews' => '^1.2'])];

        $this->expectExceptionMessageIsOrContains('It would move packages the site has: acme/reviews 1.2.0 → 1.3.0.');
        $this->packages()->update($this->name, 'beta/gallery', true);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function notMoved(): iterable
    {
        yield 'Quire\'s' => ['quire/core', true, 'quire/core moves with the site\'s updates: "site:update jane".'];
        yield 'not one it added' => ['acme/other', true, '"jane" has no acme/other added with "site:packages"'];
        yield 'without --third-party' => ['acme/reviews', false, 'Move it only when you trust its new version, with --third-party.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notMoved')]
    public function testWhatIsntMovedHere(string $package, bool $thirdParty, string $refused): void
    {
        $this->addReviews();

        $this->expectExceptionMessageIsOrContains($refused);
        $this->packages()->update($this->name, $package, $thirdParty);
    }

    public function testAlreadyTheNewest(): void
    {
        $this->addReviews();
        $this->resolved = [...$this->site(), ...$this->reviews()];

        $this->expectExceptionMessageIsOrContains('acme/reviews 1.2.0 is the newest its constraint allows ("site:packages … update acme/reviews:<constraint>" gives it another).');
        $this->packages()->update($this->name, 'acme/reviews', true);
    }

    private function packages(): SitePackages
    {
        $report = fn (): array => $this->report;

        return new SitePackages(new Composer($this->runner), $this->paths, $this->repositories, new readonly class($report) implements SiteExtensions {
            /**
             * @param \Closure(): array{extensions: list<array{id: string, package: string, status: string}>, theme: ?string} $report
             */
            public function __construct(
                private \Closure $report,
            ) {
            }

            #[\Override]
            public function of(SiteName $name): array
            {
                return ($this->report)();
            }
        });
    }

    /**
     * The site as if "add acme/reviews --third-party" had been made (and beta/gallery, which needs it).
     */
    private function addReviews(bool $gallery = false): void
    {
        $site = $this->paths->site($this->name);
        $json = json_decode((string) file_get_contents($site.'/composer.json'), true);
        \assert(\is_array($json) && \is_array($json['require']));
        $json['require']['acme/reviews'] = '^1.2';
        $json['extra'] = ['quire-server' => ['packages' => ['acme/reviews' => ['third-party' => true]]]];
        $packages = [...$this->site(), ...$this->reviews()];

        if ($gallery) {
            $json['require']['beta/gallery'] = '^0.4';
            $json['extra']['quire-server']['packages']['beta/gallery'] = ['third-party' => true];
            $packages[] = LockTest::package('beta/gallery', '0.4.0', 'quire-extension', ['acme/reviews' => '^1.2']);
        }

        file_put_contents($site.'/composer.json', json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        file_put_contents($site.'/composer.lock', LockTest::packages($packages)->content);
    }

    /**
     * A third party's theme, added, and a child theme of it that came with the site's lock.
     */
    private function addTheme(): void
    {
        $site = $this->paths->site($this->name);
        $json = json_decode((string) file_get_contents($site.'/composer.json'), true);
        \assert(\is_array($json) && \is_array($json['require']));
        $json['require']['acme/fancy'] = '^2.0';
        $json['extra'] = ['quire-server' => ['packages' => ['acme/fancy' => ['third-party' => true], 'acme/reviews' => ['third-party' => true]]]];
        file_put_contents($site.'/composer.json', json_encode($json));
        $lock = json_decode((string) file_get_contents($site.'/composer.lock'), true);
        \assert(\is_array($lock) && \is_array($lock['packages']));
        $lock['packages'][] = LockTest::package('acme/fancy', '2.0.0', 'quire-theme', extra: ['quire' => ['id' => 'fancy']]);
        $lock['packages'][] = LockTest::package('acme/fancy-child', '1.0.0', 'quire-theme', ['acme/fancy' => '^2.0'], extra: ['quire' => ['id' => 'fancy-child', 'theme' => ['parent' => 'fancy']]]);
        file_put_contents($site.'/composer.lock', json_encode($lock));
    }

    /**
     * @return list<array<string, mixed>> What a site from Quire's skeleton has (in short)
     */
    private function site(): array
    {
        return [
            LockTest::package('quire/core', 'v0.3.1', 'symfony-bundle', ['psr/log' => '^3.0', 'symfony/yaml' => '^8.1']),
            LockTest::package('quire/admin', 'v0.3.1', 'quire-extension', ['quire/core' => '^0.3']),
            LockTest::package('quire/theme-default', 'v0.3.1', 'quire-theme', ['quire/core' => '^0.3']),
            LockTest::package('symfony/yaml', 'v8.1.0'),
            LockTest::package('psr/log', '3.0.2'),
        ];
    }

    /**
     * @return list<array<string, mixed>> A third party's extension, and a library only it needs
     */
    private function reviews(): array
    {
        return [
            LockTest::package('acme/reviews', '1.2.0', 'quire-extension', ['quire/core' => '^0.3', 'acme/reviews-core' => '^1.0', 'psr/log' => '^3.0']),
            LockTest::package('acme/reviews-core', '1.0.4'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analytics(): array
    {
        return LockTest::package('quire/analytics', 'v0.3.1', 'quire-extension', ['quire/core' => '^0.3', 'quire/privacy' => '^0.3']);
    }

    /**
     * @return array<string, mixed>
     */
    private function privacy(): array
    {
        return LockTest::package('quire/privacy', 'v0.3.1', 'quire-extension', ['quire/core' => '^0.3']);
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

        self::fail($key.' didn\'t run.');
    }

    /**
     * @param array<mixed> $json
     */
    private function at(array $json, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($json) || !\array_key_exists($key, $json)) {
                return null;
            }

            $json = $json[$key];
        }

        return $json;
    }

    /**
     * @return list<string>
     */
    private function workspaces(): array
    {
        $found = glob(sys_get_temp_dir().'/quire-packages-jane-*');

        return false === $found ? [] : $found;
    }
}
