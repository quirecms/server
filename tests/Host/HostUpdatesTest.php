<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Host;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Host\HostUpdates;
use Quire\Server\Notify\Problem;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Whether the machine gets its fixes (ARC-0061): a restart pending for a kernel, Docker below its
 * floor or outside the tested major, the machine's updates stopped.
 */
final class HostUpdatesTest extends TestCase
{
    private string $directory;

    /** Docker's components, as "docker version" prints them */
    private string $components = '[{"Name":"Engine","Version":"29.10.0"},{"Name":"containerd","Version":"v2.4.2"},{"Name":"runc","Version":"1.5.2"},{"Name":"docker-init","Version":"0.19.0"}]';

    private bool $dockerAnswers = true;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quire-host-updates-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir([$this->directory.'/kernels', $this->directory.'/apt']);
        $this->kernels('7.0.0-43-generic');
        $this->facts("docker_updates=on\nreboot=machine\nupdate_hour=machine\n");
        touch($this->directory.'/apt/upgrade-stamp', time() - 3600);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testAWellPatchedMachineHasNoProblem(): void
    {
        self::assertSame([], $this->problems());
    }

    public function testANewerKernelOfTheSameKindWaitsForARestart(): void
    {
        $this->kernels('7.0.0-42-generic', '7.0.0-43-generic', '7.0.0-44-generic', '7.0.0-50-aws');

        $problems = $this->problems();

        self::assertSame(['host.kernel'], $this->keys($problems));
        self::assertStringContainsString('kernel 7.0.0-44-generic is installed, 7.0.0-43-generic runs', $problems[0]->summary);
        self::assertStringContainsString('QUIRE_SERVER_REBOOT=on', $problems[0]->advice);
    }

    public function testAMachineThatRestartsByItselfIsntToldToTurnItOn(): void
    {
        $this->kernels('7.0.0-43-generic', '7.0.0-44-generic');
        $this->facts("docker_updates=on\nreboot=on\nupdate_hour=4\nupdate_hour_applied=yes\neffective_reboot=on\neffective_update_time=*-*-* 04:30:00 UTC\n");

        $problems = $this->problems();

        self::assertSame(['host.kernel'], $this->keys($problems));
        self::assertStringNotContainsString('QUIRE_SERVER_REBOOT', $problems[0]->advice);
        self::assertStringContainsString('It restarts by itself after its next automatic updates (daily from 04:30 UTC)', $problems[0]->advice);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function updateTimes(): iterable
    {
        yield 'server.env\'s hour, as systemd has it' => ["update_hour=4\nupdate_hour_applied=yes\neffective_update_time=*-*-* 04:30:00 UTC", 'daily from 04:30 UTC'];
        yield 'written, but systemd couldn\'t be told' => ["update_hour=4\nupdate_hour_applied=no\neffective_update_time=*-*-* 06:00:00", 'daily from 04:30 UTC, once the machine restarts'];
        yield 'the machine\'s own timer' => ["update_hour=machine\neffective_update_time=*-*-* 06:00:00", 'daily from 06:00 (the machine\'s time zone)'];
        yield 'an operator\'s timer adds a time' => ["update_hour=4\nupdate_hour_applied=overridden\neffective_update_time=*-*-* 04:30:00 UTC, *-*-* 17:00:00 UTC", 'daily from 04:30 UTC and from 17:00 UTC'];
        yield 'systemd didn\'t say' => ["update_hour=machine\neffective_update_time=unknown", 'at the machine\'s own time'];
        yield 'an older release\'s facts' => ['docker_updates=on', 'at the machine\'s own time'];
    }

    #[DataProvider('updateTimes')]
    public function testWhenTheMachinesUpdatesRun(string $facts, string $said): void
    {
        $this->facts($facts."\n");

        self::assertSame($said, HostUpdates::updateTime($this->host()->facts()));
    }

    public function testVersionsCompareAsNumbersNotText(): void
    {
        $this->kernels('7.0.0-9-generic', '7.0.0-43-generic');

        self::assertSame([], $this->problems(), '9 is older than 43.');
    }

    public function testOlderKernelsLeftInstalledAreNoProblem(): void
    {
        $this->kernels('7.0.0-38-generic', '7.0.0-42-generic', '7.0.0-43-generic');

        self::assertNull($this->host()->pendingKernel());
    }

    public function testDockerBelowItsFloorIsSaidWithTheReleasesFloor(): void
    {
        $this->components = '[{"Name":"Engine","Version":"29.9.3"},{"Name":"containerd","Version":"2.3.4"},{"Name":"runc","Version":"1.3.2"}]';

        $problems = $this->problems();

        self::assertSame(['host.docker'], $this->keys($problems));
        self::assertStringContainsString('runc 1.3.2 (at least 1.3.3), containerd 2.3.4 (at least 2.3.6)', $problems[0]->summary);
        self::assertStringContainsString('the floor of Quire Server '.HostUpdates::FLOORS_OF, $problems[0]->summary);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function floors(): iterable
    {
        yield 'runc at its branch\'s floor' => ['runc', '1.3.3', null];
        yield 'runc below it' => ['runc', '1.3.2', '1.3.3'];
        yield 'runc on a newer branch than any listed' => ['runc', '1.5.2', null];
        yield 'runc on an older branch, no fixes any more' => ['runc', '1.1.15', '1.2.8'];
        yield 'containerd on a branch at its end (2.1), between listed ones' => ['containerd', '2.1.9', '2.4.1'];
        yield 'containerd with a "v"' => ['containerd', '2.4.2', null];
        yield 'Docker 28' => ['Engine', '28.5.2', '29.0.0'];
        yield 'Docker 29.10' => ['Engine', '29.10.0', null];
        yield 'a version it can\'t read is no problem' => ['runc', 'dev', null];
        yield 'a component without floors' => ['docker-init', '0.1.0', null];
    }

    #[DataProvider('floors')]
    public function testFloors(string $component, string $version, ?string $floor): void
    {
        self::assertSame($floor, HostUpdates::belowFloor($component, $version));
    }

    public function testEachComponentsBranchesAreListedOldestFirst(): void
    {
        foreach (HostUpdates::FLOORS as $component => $floors) {
            $previous = null;

            foreach (array_keys($floors) as $branch) {
                self::assertTrue(null === $previous || version_compare($previous, $branch, '<'), $component.': '.$branch);
                $previous = $branch;
            }
        }
    }

    public function testDockerNotAnsweringIsNoProblem(): void
    {
        $this->dockerAnswers = false;

        self::assertSame([], $this->problems());
    }

    public function testAnUntestedMajorSaysDockersUpdatesAreOff(): void
    {
        $this->facts("docker_updates=untested-major\ndocker_untested=docker-ce=5:30.0.1-1 containerd.io=2.5.0-1\n");
        $this->components = '[{"Name":"Engine","Version":"30.0.1"},{"Name":"containerd","Version":"2.5.0"},{"Name":"runc","Version":"1.5.2"}]';

        $problems = $this->problems();

        self::assertSame(['host.docker-major'], $this->keys($problems));
        self::assertStringContainsString('docker-ce=5:30.0.1-1 containerd.io=2.5.0-1 is installed, and this release of Quire Server is tested on Docker 29', $problems[0]->summary);
        self::assertStringContainsString('Nothing of Docker\'s is moved, held or downgraded', $problems[0]->advice);
    }

    public function testUpdatesThatStoppedRunningAreSaid(): void
    {
        touch($this->directory.'/apt/upgrade-stamp', time() - HostUpdates::UPDATES_LATE - 60);

        $problems = $this->problems();

        self::assertSame(['host.updates'], $this->keys($problems));
        self::assertStringContainsString('haven\'t run since', $problems[0]->summary);
    }

    public function testUpdatesTheOwnerTurnedOffAreNotAProblem(): void
    {
        touch($this->directory.'/apt/upgrade-stamp', time() - 30 * 86400);

        foreach (['off', 'os-updates-off', 'no-apt'] as $state) {
            $this->facts('docker_updates='.$state."\n");
            self::assertSame([], $this->problems(), $state);
        }
    }

    public function testWithoutHostSetupsFactsNothingIsGuessed(): void
    {
        unlink($this->directory.'/host-updates');
        touch($this->directory.'/apt/upgrade-stamp', time() - 30 * 86400);

        self::assertSame([], $this->problems(), 'An older release\'s host setup wrote no facts.');
        self::assertSame([], $this->host()->facts());
    }

    public function testFactsAreReadAsKeyAndValueOnly(): void
    {
        $this->facts("docker_updates=on\nnot a fact\nreboot=machine\n");

        self::assertSame(['docker_updates' => 'on', 'reboot' => 'machine'], $this->host()->facts());
    }

    public function testTheTestedMajorIsTheScriptsToo(): void
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2).'/gate/host-updates.sh');

        self::assertMatchesRegularExpression('/^(majors=\')?docker-ce 5:'.HostUpdates::TESTED_DOCKER_MAJOR.'\.\*$/m', $script);
        self::assertStringNotContainsString('#clear', $script, 'Origins are only added, never cleared: an operator\'s stay (SEC-0016).');
        self::assertStringNotContainsString('Pin-Priority: 1001', $script, 'Above 1000, apt downgrades.');
    }

    private function kernels(string ...$names): void
    {
        $listed = glob($this->directory.'/kernels/*');
        new Filesystem()->remove(false === $listed ? [] : $listed);

        foreach ($names as $name) {
            mkdir($this->directory.'/kernels/'.$name);
        }
    }

    private function facts(string $facts): void
    {
        file_put_contents($this->directory.'/host-updates', $facts);
    }

    private function host(): HostUpdates
    {
        $runner = new ScriptedRunner([
            'docker version' => fn (array $command): ProcessResult => $this->dockerAnswers ? ScriptedRunner::ok($command, $this->components."\n") : ScriptedRunner::failed($command, 'Cannot connect to the Docker daemon'),
        ]);

        return new HostUpdates($runner, 'unix:///trusted.sock', $this->directory.'/host-updates', $this->directory.'/kernels', $this->directory.'/apt', static fn (): string => '7.0.0-43-generic');
    }

    /**
     * @return list<Problem>
     */
    private function problems(): array
    {
        return $this->host()->problems();
    }

    /**
     * @param list<Problem> $problems
     *
     * @return list<string>
     */
    private function keys(array $problems): array
    {
        return array_map(static fn (Problem $problem): string => $problem->key, $problems);
    }
}
