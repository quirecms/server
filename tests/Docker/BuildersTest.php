<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\TestCase;
use Quire\Server\Docker\Builders;
use Quire\Server\Docker\PolicyContext;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Tests\GateFixture;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

final class BuildersTest extends TestCase
{
    private const array SITE = ['DOCKER_CONFIG' => '/tmp/site', 'DOCKER_HOST' => false];

    private string $root;

    /** @var array<string, string> What "docker container inspect" says of the builder */
    private array $state = [];

    private ScriptedRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-builders-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->root.'/sites/jane');
        $this->runner = new ScriptedRunner([
            'docker container inspect' => fn (array $command): ProcessResult => isset($this->state[$command[5]]) ? ScriptedRunner::ok($command, $this->state[$command[5]]."\n") : ScriptedRunner::failed($command, 'Error: No such container'),
            'docker container run' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, "abc\n"),
            'docker container start' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'docker container rm' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'docker volume rm' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'docker network inspect' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'docker buildx inspect' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            // Builders of earlier releases, one of them a site's with a project older than projects' rules
            'docker container ls' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, "buildx_buildkit_quire-jane-0123456789ab0\nbuildx_buildkit_quire-demo_x-abcdefabcdef0\nbuildx_buildkit_quire-someone-elses\n"),
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testOnUbuntuItRunsUnderTheProfileThatGrantsItAUserNamespace(): void
    {
        GateFixture::builders($this->runner, $this->root, 'yorvogate-buildkit-rootless')->ensure($this->project(), self::SITE);

        $run = $this->command('docker container run');
        self::assertContains('apparmor=yorvogate-buildkit-rootless', $run);
        self::assertNotContains('--privileged', $run);
        self::assertNotContains('no-new-privileges', $run, 'newuidmap needs its file capabilities.');
        self::assertEmpty(array_filter($run, static fn (string $argument): bool => str_starts_with($argument, '--cap-add') || str_starts_with($argument, '--publish') || str_starts_with($argument, '/')), 'No capabilities, ports or host paths.');
        self::assertSame(GateFixture::TRUSTED, $this->env('docker container run')['DOCKER_HOST'] ?? null, 'Created on the trusted socket.');
        self::assertSame(self::SITE, $this->env('docker buildx inspect'), 'Reached on the site\'s.');
    }

    public function testWhereAppArmorIsTooOldItRunsUnconfined(): void
    {
        GateFixture::builders($this->runner, $this->root, 'unconfined')->ensure($this->project(), self::SITE);

        self::assertContains('apparmor=unconfined', $this->command('docker container run'));
    }

    public function testWithoutTheHostsSetupNoBuilderIsMade(): void
    {
        $builders = GateFixture::builders($this->runner, $this->root, 'something-else');

        try {
            $builders->ensure($this->project(), self::SITE);
            self::fail('A profile host-setup doesn\'t write was used.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('"something-else"', $e->getMessage());
        }

        unlink($this->root.'/host/builder-apparmor');
        $this->expectExceptionMessageIsOrContains('see "quire-server logs host-setup"');
        $builders->ensure($this->project(), self::SITE);
    }

    public function testARunningBuilderIsUsedAsItIsAndAStoppedOneStarted(): void
    {
        $this->state['quire-buildkit-jane'] = Builders::BUILDKIT.' true';
        GateFixture::builders($this->runner, $this->root)->ensure($this->project(), self::SITE);
        self::assertSame(['docker container inspect', 'docker buildx inspect', 'docker buildx inspect'], $this->runner->keys());

        $this->runner->runs = [];
        $this->state['quire-buildkit-jane'] = Builders::BUILDKIT.' false';
        GateFixture::builders($this->runner, $this->root)->ensure($this->project(), self::SITE);
        self::assertSame(['docker', 'container', 'start', 'quire-buildkit-jane'], $this->command('docker container start'));
        self::assertNotContains('docker container run', $this->runner->keys());
    }

    public function testOnlyTheBuildersOfEarlierReleasesGo(): void
    {
        self::assertSame(['buildx_buildkit_quire-jane-0123456789ab0', 'buildx_buildkit_quire-demo_x-abcdefabcdef0'], GateFixture::builders($this->runner, $this->root)->removeLegacy());
        self::assertContains(['docker', 'volume', 'rm', '--force', 'buildx_buildkit_quire-jane-0123456789ab0_state'], array_column($this->runner->runs, 'command'));
    }

    private function project(): SiteProject
    {
        return new SiteProject($this->root.'/sites/jane', $this->root.'/sites/jane/prod.env', new PolicyContext('jane', $this->root.'/sites/jane', []));
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

        self::fail('No '.$key);
    }

    /**
     * @return array<string, string|false>
     */
    private function env(string $key): array
    {
        foreach ($this->runner->runs as $run) {
            if (ScriptedRunner::key($run['command']) === $key) {
                return $run['env'];
            }
        }

        self::fail('No '.$key);
    }
}
