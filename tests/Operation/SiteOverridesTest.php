<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Operation;

use Quire\Server\Docker\Compose;
use Quire\Server\Docker\ComposeFiles;
use Quire\Server\Docker\ComposePolicy;
use Quire\Server\Docker\Images;
use Quire\Server\Docker\RuntimeImages;
use Quire\Server\Operation\SiteOverrides;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\RuntimeBuilds;
use Quire\Server\Registry\RuntimeFloors;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\GateFixture;
use Quire\Server\Tests\LinkAliases;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Sites as an earlier release left them, brought up to date at the agent's start, with Docker scripted:
 * their override written as this release does, and the image they run named as this release names it.
 */
final class SiteOverridesTest extends RegistryTestCase
{
    private const string RUNNING = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private string $root;
    private ServerPaths $paths;
    private SiteRegistry $registry;
    private ScriptedRunner $runner;

    /** @var array<string, string> Image name => ID */
    private array $tags = [];

    private bool $refused = false;

    /** The seccomp options of the site's containers, as Docker shows them (YorvoGate 0.3 writes "seccomp=builtin") */
    private string $securityOpt = '["no-new-privileges=true","seccomp=builtin"]';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-overrides-'.bin2hex(random_bytes(6));
        $this->paths = new ServerPaths($this->root);
        $this->registry = new SiteRegistry($this->connection, $this->schema);
        $this->runner = new ScriptedRunner([
            'docker image inspect' => fn (array $command): ProcessResult => isset($this->tags[$command[5]]) ? ScriptedRunner::ok($command, $this->tags[$command[5]]."\n") : ScriptedRunner::failed($command, 'No such image'),
            'docker container inspect' => fn (array $command): ProcessResult => match (true) {
                // Each of the site's containers' seccomp options (Compose::up)
                '{{json .HostConfig.SecurityOpt}}' === $command[4] => ScriptedRunner::ok($command, implode("\n", array_fill(0, \count($command) - 5, $this->securityOpt))."\n"),
                'demo-x-php-1' === $command[5] || 'jane-php-1' === $command[5] => ScriptedRunner::ok($command, self::RUNNING."\n"),
                default => ScriptedRunner::failed($command, 'No such container'),
            },
            // The site's containers: one, whose seccomp option is $securityOpt
            'docker container ls' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, "c0ffee\n"),
            'docker image tag' => function (array $command): ProcessResult {
                $this->tags[$command[4]] = $command[3];

                return ScriptedRunner::ok($command);
            },
            // What the policy reads: the project, nothing it refuses (or, for a test, something it does)
            'docker compose config' => fn (array $command): ProcessResult => ScriptedRunner::ok($command, (string) json_encode(['name' => $command[7], 'services' => $this->refused ? ['php' => ['privileged' => true]] : []])),
            'docker compose up' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            ...GateFixture::networkScript(),
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testSitesOfAnEarlierReleaseAreBroughtUpToDate(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $this->site('demo', 'demo-x', Site::OWN_DATABASE);
        $this->registry->add(new SiteName('gone'), [new Domain('gone.example')], Site::SHARED_DATABASE, 'gone');
        $this->registry->setStatus(new SiteName('gone'), Site::REMOVED);

        self::assertSame([], $this->subject()->refresh());

        $jane = (string) file_get_contents($this->paths->site(new SiteName('jane')).'/'.SiteFiles::OVERRIDE);
        self::assertStringContainsString('image: quire-sites/jane/php-prod', $jane);
        self::assertStringContainsString('jane.example', $jane, 'Its domains, from the registry.');
        self::assertStringContainsString('image: quire-sites/demo-x/php-prod', (string) file_get_contents($this->paths->site(new SiteName('demo')).'/'.SiteFiles::OVERRIDE), 'An adopted site\'s own project.');
        ksort($this->tags);
        self::assertSame(['quire-sites/demo-x/php-prod' => self::RUNNING, 'quire-sites/jane/php-prod' => self::RUNNING], $this->tags, 'The image each runs, named anew: its next update can be undone.');
        self::assertFileDoesNotExist($this->paths->site(new SiteName('gone')).'/'.SiteFiles::OVERRIDE);
        self::assertCount(2, array_keys($this->runner->keys(), 'docker compose up', true), 'Each started again with it, once.');
        self::assertStringContainsString('jane.example.quire.internal', $jane, 'Named on its link by a name no public DNS answers.');

        $this->runner->runs = [];
        self::assertSame([], $this->subject()->refresh());
        self::assertNotContains('docker compose up', $this->runner->keys(), 'Nothing changes twice.');
    }

    public function testASiteThatCantStartWithItKeepsTheOneItRuns(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $this->refused = true;

        $failed = $this->subject()->refresh();

        self::assertStringContainsString('privileged', $failed['jane'] ?? '');
        self::assertSame("services:\n  php:\n    cap_drop: [ALL]\n", file_get_contents($this->paths->site(new SiteName('jane')).'/'.SiteFiles::OVERRIDE), 'As it was, matching its containers.');
        self::assertNotContains('docker compose up', $this->runner->keys());
    }

    public function testASiteOfAReleaseBeforeLinksMovesToItsLink(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $reached = [];
        $this->runner->script['docker exec quire-server-front-1'] = static function (array $command) use (&$reached): ProcessResult {
            $command = array_values(array_map(static fn (mixed $part): string => \is_scalar($part) ? (string) $part : '', $command));
            $reached[] = $command[7];

            return ScriptedRunner::ok($command);
        };

        self::assertSame([], $this->subject()->refresh());

        $jane = (string) file_get_contents($this->paths->site(new SiteName('jane')).'/'.SiteFiles::OVERRIDE);
        self::assertStringContainsString('quire-link-jane', $jane);
        self::assertStringNotContainsString('quire-edge', $jane, 'Off the networks every site shared.');
        self::assertStringContainsString('subnet: 10.72.0.32/27', $jane, 'Its own network at its addresses: Compose makes it again there on "up".');
        self::assertSame(['edge.jane.example.quire.internal'], $reached, 'Once it runs, the front door is asked to reach it there, by its name of 0.6.');
    }

    public function testASiteWhoseNetworkMovesHasEveryContainerMadeAgain(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $subnet = "172.18.0.0/16 \n";
        $this->runner->script['docker network inspect'] = static function (array $command) use (&$subnet): ProcessResult {
            $command = array_values(array_map(static fn (mixed $part): string => \is_scalar($part) ? (string) $part : '', $command));

            return match (end($command)) {
                'jane_default' => ScriptedRunner::ok($command, $subnet),
                'quire-data' => ScriptedRunner::ok($command, "172.19.0.0/16 \n"),
                default => ScriptedRunner::ok($command, end($command)."\n"),
            };
        };

        $this->subject()->refresh();

        self::assertContains('--force-recreate', $this->lastUp(), 'Docker\'s addresses: Compose makes its network again, and would reconnect containers it keeps without their service\'s name ("database" wouldn\'t resolve).');

        // At its addresses already (a change of its override for another reason): only what changed
        $subnet = "10.72.0.32/27 \n";
        new Filesystem()->dumpFile($this->paths->site(new SiteName('jane')).'/'.SiteFiles::OVERRIDE, "services: {}\n");
        $this->subject()->refresh();
        self::assertNotContains('--force-recreate', $this->lastUp());
    }

    /**
     * Before any site is brought up to date, every site's scope is as this release writes it: one of
     * the release before may hold a name the gate now gives another site, and it would refuse that
     * site's scope (both sites') until it's rewritten. None is removed here: a registered site whose
     * project can't be made now keeps its scope as it is, and so does any other (the agent's start
     * removes those of sites that are gone).
     */
    public function testEverySitesScopeIsWrittenAnewFirstAndNoneRemoved(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $this->site('demo-x', 'demo-x', Site::SHARED_DATABASE);
        // As a release before 0.6 wrote it: the name of before only
        new Filesystem()->dumpFile($this->root.'/gate/scopes/jane.json', '{"networks": {"quire-link-jane": {"aliases": ["jane.example.quire.internal"]}}}');
        // A registered site without its folder now: its scope can't be made
        $this->registry->add(new SiteName('kim'), [new Domain('kim.example')], Site::SHARED_DATABASE, 'kim');
        $this->registry->setStatus(new SiteName('kim'), Site::RUNNING);
        $kims = '{"as": "it was"}';
        new Filesystem()->dumpFile($this->root.'/gate/scopes/kim.json', $kims);
        new Filesystem()->dumpFile($this->root.'/gate/scopes/gone.json', '{}');

        self::assertSame([], $this->subject()->refresh());

        self::assertContains(SiteFiles::edgeName('jane.example'), LinkAliases::in(json_decode((string) file_get_contents($this->root.'/gate/scopes/jane.json'), true), 'quire-link-jane'));
        self::assertFileExists($this->root.'/gate/scopes/demo-x.json');
        self::assertSame($kims, file_get_contents($this->root.'/gate/scopes/kim.json'), 'Kept byte for byte: its socket stays open.');
        self::assertFileExists($this->root.'/gate/scopes/gone.json', 'Left to the agent\'s start.');
    }

    public function testContainersMadeBeforeTheGateHardenedThemAreMadeAgain(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        // Made under YorvoGate 0.2: no seccomp option of the gate's
        $this->securityOpt = '["no-new-privileges=true"]';

        $this->subject()->refresh();

        self::assertContains('--force-recreate', $this->lastUp(), 'Every container, the worker too: Compose would make again only those whose configuration changed.');

        // Docker can't say: said, and only what changed is made again
        $this->runner->script['docker container ls'] = static fn (array $command): ProcessResult => ScriptedRunner::failed(array_values(array_filter($command, \is_string(...))), 'Cannot connect');
        new Filesystem()->dumpFile($this->paths->site(new SiteName('jane')).'/'.SiteFiles::OVERRIDE, "services: {}\n");
        $this->subject()->refresh();
        self::assertNotContains('--force-recreate', $this->lastUp());
        $this->runner->script['docker container ls'] = static fn (array $command): ProcessResult => ScriptedRunner::ok(array_values(array_filter($command, \is_string(...))), "c0ffee\n");

        // Made under 0.3: only what changed
        $this->securityOpt = '["no-new-privileges=true","seccomp=builtin"]';
        new Filesystem()->dumpFile($this->paths->site(new SiteName('jane')).'/'.SiteFiles::OVERRIDE, "services: {}\n");
        $this->subject()->refresh();
        self::assertNotContains('--force-recreate', $this->lastUp());
    }

    /**
     * @return list<string>
     */
    private function lastUp(): array
    {
        $ups = array_values(array_filter($this->runner->runs, static fn (array $run): bool => 'docker compose up' === ScriptedRunner::key($run['command'])));

        return [] === $ups ? [] : array_last($ups)['command'];
    }

    public function testAMovedSiteTheFrontDoorDoesntReachIsSaid(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $this->runner->script['docker exec quire-server-front-1'] = static fn (array $command): ProcessResult => ScriptedRunner::failed(array_values(array_map(static fn (mixed $part): string => \is_scalar($part) ? (string) $part : '', $command)), '');

        $failed = $this->subject()->refresh();

        self::assertStringContainsString('the front door doesn\'t reach it on its link (edge.jane.example.quire.internal:80)', $failed['jane'] ?? '');
        self::assertStringContainsString('quire-link-jane', (string) file_get_contents($this->paths->site(new SiteName('jane')).'/'.SiteFiles::OVERRIDE), 'It runs with it: kept.');
    }

    public function testAFailedSiteGetsItsOverrideForItsNextStart(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $this->registry->setStatus(new SiteName('jane'), Site::FAILED);

        self::assertSame([], $this->subject()->refresh());

        self::assertStringContainsString('quire-link-jane', (string) file_get_contents($this->paths->site(new SiteName('jane')).'/'.SiteFiles::OVERRIDE), 'An earlier release\'s would join networks this one refuses.');
        self::assertNotContains('docker compose up', $this->runner->keys(), 'Not started: it wasn\'t running.');
    }

    public function testANameItHasAlreadyIsKept(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $this->tags['quire-sites/jane/php-prod'] = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

        $this->subject()->refresh();

        self::assertNotContains('docker image tag', $this->runner->keys());
    }

    private function site(string $name, string $project, string $mode): void
    {
        $this->registry->add(new SiteName($name), [new Domain($name.'.example')], $mode, $project);
        $this->registry->setStatus(new SiteName($name), Site::RUNNING);
        new Filesystem()->dumpFile($this->paths->site(new SiteName($name)).'/'.SiteFiles::OVERRIDE, "services:\n  php:\n    cap_drop: [ALL]\n");
        new Filesystem()->dumpFile($this->paths->site(new SiteName($name)).'/'.SiteFiles::ENV_FILE, "COMPOSE_FILE='compose.yaml:compose.server.yaml'\nCOMPOSE_PROJECT_NAME='".$project."'\n");
    }

    private function subject(): SiteOverrides
    {
        return new SiteOverrides($this->registry, new SiteFiles(), $this->paths, new Images($this->runner, GateFixture::gate($this->runner, $this->root, 'jane', 'demo-x', 'gone')), new SiteLock($this->connection, $this->schema), new Compose($this->runner, new ComposePolicy(), new ComposeFiles(), new RuntimeImages($this->runner, new RuntimeFloors($this->connection, $this->schema)), new RuntimeBuilds($this->connection, $this->schema), GateFixture::gate($this->runner, $this->root, 'jane', 'demo-x', 'gone'), GateFixture::builders($this->runner, $this->root)), GateFixture::links($this->runner, $this->connection, $this->schema), GateFixture::gate($this->runner, $this->root, 'jane', 'demo-x', 'gone'), new SiteNetworks($this->connection, $this->schema));
    }
}
