<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerUpdate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\ServerUpdate\UpdateFloor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The release's first step at each start: an update from a release older than 0.6.0 stops before any
 * of its containers runs, so the updater that ran it puts the server back as it ran.
 */
final class UpdateFloorTest extends TestCase
{
    private const string SERVICE = 'update-from-0.6-or-later';

    /**
     * @return iterable<string, array{?string}>
     */
    public static function startsThatGoOn(): iterable
    {
        yield 'no update under way (a start, a fresh install)' => [null];
        yield 'an empty record' => [''];
        yield 'from 0.6.0' => ['{"from": "0.6.0", "to": "0.7.0"}'];
        yield 'from 0.6.1' => ['{"from": "0.6.1", "to": "0.7.0-rc.1"}'];
        yield 'from this release (an update to a later one undone)' => ['{"from": "0.7.0", "to": "0.6.1"}'];
        yield 'from a server built from a checkout' => ['{"from": "dev", "to": "0.7.0"}'];
        yield 'a record the updater itself reads (not this check\'s)' => ['{not json'];
    }

    #[DataProvider('startsThatGoOn')]
    public function testAStartGoesOn(?string $record): void
    {
        self::assertNull(UpdateFloor::refusal($record));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function updatesFromBefore(): iterable
    {
        yield '0.5.2' => ['{"from": "0.5.2", "to": "0.7.0", "started_at": "2026-11-24T18:00:00+01:00"}', '0.5.2'];
        yield '0.3.1' => ['{"from": "0.3.1", "to": "0.7.0"}', '0.3.1'];
        yield 'a release candidate of 0.6.0' => ['{"from": "v0.6.0-rc.3", "to": "0.7.0"}', '0.6.0-rc.3'];
    }

    #[DataProvider('updatesFromBefore')]
    public function testAnUpdateFromBefore060Stops(string $record, string $from): void
    {
        $refusal = UpdateFloor::refusal($record);

        self::assertNotNull($refusal);
        self::assertStringContainsString('updates a server on 0.6.0 or later; this one runs '.$from, $refusal);
        self::assertStringContainsString('"quire-server update --to=0.6.1"', $refusal, 'It says what to do.');
        self::assertStringContainsString('The server runs as it was', $refusal, 'And that nothing was lost.');
    }

    public function testTheStepStopsTheStartWithItsReason(): void
    {
        $work = sys_get_temp_dir().'/quire-floor-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($work.'/swap.json', '{"from": "0.5.2", "to": "0.7.0"}');
        $script = \dirname(__DIR__, 2).'/bin/update-floor';

        try {
            $refused = new Process([\PHP_BINARY, $script, $work.'/swap.json']);
            $refused->run();
            self::assertSame(1, $refused->getExitCode());
            self::assertStringContainsString('this one runs 0.5.2', $refused->getErrorOutput());

            $start = new Process([\PHP_BINARY, $script, $work.'/none.json']);
            $start->run();
            self::assertSame(0, $start->getExitCode(), 'Without a record, the start goes on.');
        } finally {
            new Filesystem()->remove($work);
        }
    }

    public function testEveryContainerOfTheReleaseStartsAfterIt(): void
    {
        $services = $this->services();

        foreach (['front', 'agent', 'gate', 'host-setup'] as $name) {
            $dependsOn = \is_array($services[$name]['depends_on'] ?? null) ? $services[$name]['depends_on'] : [];
            self::assertSame(['condition' => 'service_completed_successfully'], $dependsOn[self::SERVICE] ?? null, $name.' starts only once it passed.');
        }
    }

    public function testItGetsNothingButTheUpdatesRecord(): void
    {
        $step = $this->services()[self::SERVICE];

        self::assertSame('none', $step['network_mode'] ?? null);
        self::assertTrue($step['read_only'] ?? null);
        self::assertSame(['ALL'], $step['cap_drop'] ?? null);
        self::assertSame(['${QUIRE_SERVER_ROOT:-/srv/quire}/backups/server:/swap:ro'], $step['volumes'] ?? null, 'Only the folder of the update\'s record, read-only: no Docker socket, no backups.');
        self::assertArrayNotHasKey('environment', $step, 'No secrets.');
        self::assertArrayNotHasKey('depends_on', $step);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function services(): array
    {
        $compose = Yaml::parseFile(\dirname(__DIR__, 2).'/compose.yaml');
        \assert(\is_array($compose) && \is_array($compose['services']));
        $services = [];

        foreach ($compose['services'] as $name => $service) {
            \assert(\is_string($name) && \is_array($service));
            $services[$name] = array_filter($service, is_string(...), \ARRAY_FILTER_USE_KEY);
        }

        return $services;
    }
}
