<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\TestCase;
use Quire\Server\Docker\Builders;
use Quire\Server\Docker\ComposePolicy;
use Quire\Server\Docker\Gate;
use Quire\Server\Docker\PolicyContext;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Network\SiteNetworkRange;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\SymfonyProcessRunner;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\GateFixture;
use Quire\Server\Tests\LinkAliases;
use Quire\Server\Tests\LoadedScopeStatus;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class GateTest extends TestCase
{
    private const string SERVER = __DIR__.'/../..';

    private string $root;

    private ScriptedRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-gate-'.bin2hex(random_bytes(6));
        $this->runner = new ScriptedRunner();
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testASitesScopeIsWhatTheServerAllowsIt(): void
    {
        self::assertSame([
            // Its format, first (a later YorvoGate refuses a scope file without it)
            'version' => 1,
            'owner' => ['label' => 'com.docker.compose.project', 'value' => 'jane'],
            'prefixes' => ['jane-', 'jane_'],
            'images' => ['quire-sites/jane/'],
            'networks' => [
                // Its own link only (D-34): Compose's own aliases (the service's name and its
                // container's), and its domains', where the front door finds it
                'quire-link-jane' => ['aliases' => ['edge.jane.example.quire.internal', 'edge.www.jane.example.quire.internal', 'jane-php-1', 'jane-worker-1', 'jane.example.quire.internal', 'php', 'worker', 'www.jane.example.quire.internal']],
            ],
            'builders' => ['quire-buildkit-jane'],
            // Where its networks' addresses must be: its project's /27 (the gate refuses any other)
            'subnets' => ['10.72.0.32/27'],
        ], $this->gate()->scope($this->project('jane')));
    }

    public function testASiteWithItsOwnDatabaseDoesntJoinTheServers(): void
    {
        $scope = $this->gate()->scope($this->project('demo', Site::OWN_DATABASE));

        self::assertSame(['quire-link-demo' => ['aliases' => ['demo-php-1', 'demo.example.quire.internal', 'edge.demo.example.quire.internal', 'edge.www.demo.example.quire.internal', 'php', 'www.demo.example.quire.internal']]], $scope['networks'] ?? null, 'Its php for the front door; its worker stays off: it reaches no PostgreSQL there.');
    }

    public function testEachOperationHasTheScopeWrittenAndTheSitesOwnDockerConfiguration(): void
    {
        $gate = $this->gate('jane');
        $env = $gate->site($this->project('jane'));
        $file = $this->root.'/gate/scopes/jane.json';

        self::assertSame(['DOCKER_CONFIG' => $this->root.'/gate/docker/jane', 'DOCKER_HOST' => false, 'DOCKER_CONTEXT' => false, 'BUILDX_BUILDER' => false], $env, 'Nothing else decides where Docker\'s CLI goes.');
        self::assertSame($gate->scope($this->project('jane')), json_decode((string) file_get_contents($file), true));
        self::assertSame('600', \sprintf('%o', fileperms($file) & 0o777), 'Only its owner may change it, or the gate refuses it.');
        self::assertSame('700', \sprintf('%o', fileperms($this->root.'/gate/docker/jane') & 0o777));
        self::assertSame('{"currentContext":"quire"}', file_get_contents($this->root.'/gate/docker/jane/config.json'));
        $context = json_decode((string) file_get_contents($this->root.'/gate/docker/jane/contexts/meta/'.hash('sha256', 'quire').'/meta.json'), true);
        self::assertIsArray($context);
        self::assertSame(['docker' => ['Host' => 'unix://'.$this->root.'/gate/sites/jane.sock', 'SkipTLSVerify' => false]], $context['Endpoints'] ?? null, 'Its own socket, for Compose and for its builder.');
        self::assertSame([], $this->runner->runs, 'No program runs for it.');

        $written = filemtime($file);
        clearstatcache();
        touch($file, 1_000_000);
        $gate->site($this->project('jane'));
        clearstatcache();
        self::assertSame(1_000_000, filemtime($file), 'Unchanged: not written again.');
        self::assertNotFalse($written);
    }

    public function testTheGateMustOpenTheSitesSocket(): void
    {
        $gate = new Gate($this->runner, GateFixture::TRUSTED, $this->root.'/gate/scopes', $this->root.'/gate/sites', $this->root.'/gate/docker', new LoadedScopeStatus());
        $this->expectExceptionMessageIsOrContains('The Docker gate didn\'t open the socket of "jane"');

        $gate->site($this->project('jane'));
    }

    public function testTheGateIsAskedAboutTheExactFileWrittenAndWhenItsGone(): void
    {
        GateFixture::open($this->root, 'jane');
        $status = new LoadedScopeStatus();
        $gate = new Gate($this->runner, GateFixture::TRUSTED, $this->root.'/gate/scopes', $this->root.'/gate/sites', $this->root.'/gate/docker', $status);

        $gate->site($this->project('jane'));
        $written = hash_file('sha256', $this->root.'/gate/scopes/jane.json');
        $gate->site($this->project('jane'));
        $gate->remove('jane');

        self::assertSame([['jane', $written], ['jane', $written], ['jane', null]], $status->asked, 'The file as written; asked again unchanged (the gate may have refused it since); then whether it\'s gone.');
    }

    public function testNothingIsDoneForASiteWhoseScopeTheGateRefused(): void
    {
        GateFixture::open($this->root, 'jane');
        $gate = new Gate($this->runner, GateFixture::TRUSTED, $this->root.'/gate/scopes', $this->root.'/gate/sites', $this->root.'/gate/docker', new LoadedScopeStatus(['jane' => ['state' => 'reserved', 'reason' => 'its prefix "jane-" collides with "jane-blog"']]));
        $this->expectExceptionMessageIsOrContains('The Docker gate refused the scope of "jane" (reserved): its prefix "jane-" collides with "jane-blog"');

        $gate->site($this->project('jane'));
    }

    public function testNoScopeActsForTheServersOwnNames(): void
    {
        // Nor a project with "_": Compose's aliases would be names Docker's DNS doesn't take. Nor 12 hex
        // digits: the prefix "<project>_" would be a recreated container's name, which the gate refuses.
        foreach (['quire-server', 'quire', '../jane', 'Jane', '', 'demo_x', 'deadbeefcafe'] as $project) {
            try {
                SiteProject::checked($project);
                self::fail($project.' was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame('jane-blog', SiteProject::checked('jane-blog'));
    }

    public function testAtTheStartOnlyTheRegistrysSitesHaveAScope(): void
    {
        $gate = $this->gate('jane', 'jane-blog');
        new Filesystem()->dumpFile($this->root.'/gate/scopes/gone.json', '{}');
        new Filesystem()->mkdir($this->root.'/gate/docker/gone');
        new Filesystem()->mkdir($this->root.'/sites/quire-server');

        $outcome = $gate->reconcile([$this->project('jane'), $this->project('jane-blog'), new SiteProject($this->root.'/sites/quire-server', $this->root.'/sites/quire-server/prod.env', new PolicyContext('quire-server', $this->root.'/sites/quire-server', []))]);

        self::assertSame(['gone'], $outcome['removed']);
        self::assertSame(['quire-server'], array_keys($outcome['failed']), 'Said, and the others go on.');
        self::assertFileExists($this->root.'/gate/scopes/jane.json');
        self::assertFileExists($this->root.'/gate/scopes/jane-blog.json');
        self::assertFileDoesNotExist($this->root.'/gate/scopes/gone.json');
        self::assertFileDoesNotExist($this->root.'/gate/scopes/quire-server.json');
        self::assertDirectoryDoesNotExist($this->root.'/gate/docker/gone');

        $gate->remove('jane');
        self::assertFileDoesNotExist($this->root.'/gate/scopes/jane.json');
        self::assertDirectoryDoesNotExist($this->root.'/gate/docker/jane');
    }

    /**
     * The gate itself (YorvoGate, the image the server runs) reads the server's settings and the scopes
     * the agent writes: sites whose names nest included.
     */
    public function testTheGateAcceptsWhatTheAgentWrites(): void
    {
        $gate = $this->gate('jane', 'jane-blog', 'demo');

        foreach ([$this->project('jane', others: ['jane-blog', 'demo']), $this->project('jane-blog', others: ['jane', 'demo']), $this->project('demo', Site::OWN_DATABASE, ['jane', 'jane-blog'])] as $project) {
            $gate->site($project);
        }

        $check = $this->check();

        self::assertTrue($check->successful(), $check->output.$check->errorOutput);
        self::assertStringContainsString('sites (scoped, scope_sockets '.$this->root.'/gate/sites): ok, 3 scopes', $check->output);
    }

    /**
     * No site's prefix ("<project>-") covers a name "edge.<domain>.quire.internal": no project's name has a
     * ".". The names of releases before 0.6 ("<domain>.quire.internal") are kept only where no other site's
     * prefix covers them, which the gate refuses (YorvoGate 0.3) for both sites.
     */
    public function testNoSitesPrefixCoversAnotherSitesNamesOnItsLink(): void
    {
        $sites = ['edge' => ['edge.example'], 'edge-x' => ['edge-x.com'], 'jane' => ['jane.example'], 'jane-blog' => ['jane-blog.example']];
        $gate = $this->gate(...array_keys($sites));

        foreach ($sites as $name => $domains) {
            $gate->site($this->project($name, others: array_values(array_diff(array_keys($sites), [$name])), domains: $domains));
        }

        $check = $this->check();
        self::assertTrue($check->successful(), $check->output.$check->errorOutput);
        self::assertStringContainsString('ok, 4 scopes', $check->output);

        $aliases = fn (string $name): array => LinkAliases::in(json_decode((string) file_get_contents($this->root.'/gate/scopes/'.$name.'.json'), true), 'quire-link-'.$name);
        self::assertContains('edge.jane-blog.example.quire.internal', $aliases('jane-blog'));
        self::assertNotContains('jane-blog.example.quire.internal', $aliases('jane-blog'), 'Under "jane-": the gate gives it to jane.');
        self::assertNotContains('edge-x.com.quire.internal', $aliases('edge-x'), 'Under "edge-".');
        self::assertContains('jane.example.quire.internal', $aliases('jane'), 'No other site\'s prefix covers it: kept for a rollback to 0.5.');
        self::assertContains('edge.edge.example.quire.internal', $aliases('edge'));
    }

    public function testTheGateHoldsSitesToWhatThePolicyAllows(): void
    {
        $config = Yaml::parseFile(self::SERVER.'/gate/yorvogate.yaml');
        $listeners = \is_array($config) && \is_array($config['listeners'] ?? null) ? $config['listeners'] : [];
        $sites = array_first(array_filter($listeners, static fn (mixed $listener): bool => \is_array($listener) && 'sites' === ($listener['name'] ?? null))) ?? [];
        $containers = \is_array($sites['containers'] ?? null) ? $sites['containers'] : [];

        self::assertSame(ComposePolicy::ALLOWED_CAPABILITIES, $containers['capabilities'] ?? null);
        self::assertTrue($containers['drop_all_capabilities'] ?? null);
        self::assertTrue($containers['no_new_privileges'] ?? null);
        self::assertSame(ComposePolicy::ULIMITS, $containers['ulimits'] ?? null);
        self::assertSame(ComposePolicy::MAX_MEMORY, $containers['max_memory'] ?? null);
        self::assertSame(ComposePolicy::MAX_PIDS, $containers['max_pids'] ?? null);
        self::assertSame([PolicyContext::IMAGES], $containers['protected_images'] ?? null);
        self::assertSame(Builders::APPARMOR, $sites['builder_apparmor_profiles'] ?? null);
        self::assertArrayNotHasKey('allow_privileged_builders', $sites, 'A privileged builder is root on the host for the site.');
        self::assertSame(['trusted', 'sites'], array_map(static fn (mixed $listener): mixed => \is_array($listener) ? $listener['name'] ?? null : null, $listeners));
        self::assertSame('unix:///run/yorvogate/trusted/trusted.sock', \is_array($listeners[0] ?? null) ? $listeners[0]['address'] ?? null : null, 'No TCP: no network reaches the gate.');
    }

    public function testTheServerRunsTheGateTheTestsCheck(): void
    {
        $compose = Yaml::parseFile(self::SERVER.'/compose.yaml');
        $services = \is_array($compose) && \is_array($compose['services'] ?? null) ? $compose['services'] : [];
        $image = \is_array($services['gate'] ?? null) ? $services['gate']['image'] ?? null : null;
        self::assertIsString($image);
        self::assertMatchesRegularExpression('#^ghcr\.io/yorvolabs/yorvogate:[0-9.]+(?:-rc\.[0-9]+)?@sha256:[0-9a-f]{64}\z#', $image);
        self::assertStringContainsString('FROM '.$image.' AS yorvogate', (string) file_get_contents(self::SERVER.'/Dockerfile'));
    }

    public function testTheDockerProxyOfAnEarlierReleaseGoes(): void
    {
        $this->runner = new ScriptedRunner([
            'docker container ls' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, "0123456789ab\n"),
            'docker container rm' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'docker network inspect' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, "quire-docker\n"),
            'docker network rm' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
        ]);

        self::assertTrue($this->gate()->removeDockerProxy());
        self::assertSame([
            ['docker', 'container', 'ls', '--all', '--quiet', '--filter', 'label=com.docker.compose.project=quire-server', '--filter', 'label=com.docker.compose.service=docker-proxy'],
            ['docker', 'container', 'rm', '--force', '0123456789ab'],
            ['docker', 'network', 'inspect', '--format', '{{.Name}}', 'quire-docker'],
            ['docker', 'network', 'rm', 'quire-docker'],
        ], array_column($this->runner->runs, 'command'));
        self::assertSame(GateFixture::TRUSTED, $this->runner->runs[0]['env']['DOCKER_HOST'] ?? null);

        $this->runner = new ScriptedRunner([
            'docker container ls' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, ''),
            'docker network inspect' => static fn (array $command): ProcessResult => ScriptedRunner::failed($command, 'Error: No such network: quire-docker'),
        ]);
        self::assertFalse($this->gate()->removeDockerProxy(), 'Once gone, nothing to do.');
    }

    private function gate(string ...$open): Gate
    {
        return GateFixture::gate($this->runner, $this->root, ...$open);
    }

    /**
     * @param list<string>      $others  The other sites' projects
     * @param list<string>|null $domains Its domains, "<name>.example" and "www.<name>.example" if null
     */
    private function project(string $name, string $database = Site::SHARED_DATABASE, array $others = [], ?array $domains = null): SiteProject
    {
        $site = new SiteName($name);
        $paths = new ServerPaths($this->root);
        new Filesystem()->mkdir($paths->site($site));
        $domains ??= [$site->value.'.example', 'www.'.$site->value.'.example'];

        return SiteProject::of($paths, $site, array_map(static fn (string $domain): Domain => new Domain($domain), $domains), $name, $database, new SiteNetworkRange(SiteNetworkRange::DEFAULT)->block(0), $others);
    }

    /**
     * Runs the gate's own check (YorvoGate, the image the server runs) on the server's settings and the
     * scope files in the test's folder.
     */
    private function check(): ProcessResult
    {
        $config = str_replace(['/etc/yorvogate/sites', '/run/yorvogate/sites', '/run/yorvogate/trusted', '/var/lib/quire-gate/'], [$this->root.'/gate/scopes', $this->root.'/gate/sites', $this->root.'/gate/trusted', $this->root.'/gate/state/'], (string) file_get_contents(self::SERVER.'/gate/yorvogate.yaml'));
        new Filesystem()->dumpFile($this->root.'/yorvogate.yaml', $config);
        new Filesystem()->mkdir($this->root.'/gate/state', 0o700);

        return new SymfonyProcessRunner()->run(['yorvogate', 'check', '--config', $this->root.'/yorvogate.yaml'], timeout: 60);
    }
}
