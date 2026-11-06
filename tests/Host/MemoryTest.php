<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Host;

use PHPUnit\Framework\TestCase;
use Quire\Server\Host\Memory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * The machine's memory (D-40): a new site isn't built without room for it, and the server's own
 * containers are the last the kernel stops when memory runs out.
 */
final class MemoryTest extends TestCase
{
    private string $meminfo;

    #[\Override]
    protected function setUp(): void
    {
        $this->meminfo = sys_get_temp_dir().'/quire-meminfo-'.bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->meminfo);
    }

    public function testItReadsWhatTheKernelSays(): void
    {
        // As a 4 GB droplet's kernel writes it
        $this->machine(4_005_000, 2_980_000);

        self::assertSame(4_005_000 * 1024, $this->memory()->total());
        self::assertSame(2_980_000 * 1024, $this->memory()->available());
    }

    public function testANewSiteIsBuiltWithRoomForIt(): void
    {
        $this->machine(4_005_000, Memory::BUILD_MEGABYTES * 1024);
        $this->memory()->ensureRoomToBuild();

        $this->expectNotToPerformAssertions();
    }

    public function testNotOnAMachineWithoutRoom(): void
    {
        $this->machine(32_859_000, 900 * 1024);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('This server has 900 MB of memory free: building a new site takes about 1.5 GB, and running it about 200 MB. Add memory, or remove a site, then try again. (QUIRE_SERVER_BUILD_MEMORY_MB in server.env sets what a build needs, 1536 MB here.)');
        $this->memory()->ensureRoomToBuild();
    }

    public function testWhatABuildNeedsIsTheOwnersToSet(): void
    {
        $this->machine(4_005_000, 900 * 1024);
        new Memory(800, $this->meminfo)->ensureRoomToBuild();

        $this->expectExceptionMessageIsOrContains('1536 MB here');
        $this->memory()->ensureRoomToBuild();
    }

    public function testAMachineWhoseMemoryCantBeReadIsntRefused(): void
    {
        self::assertNull($this->memory()->available(), 'No file.');
        new Filesystem()->dumpFile($this->meminfo, "MemTotal:       4005000 kB\nMemFree:  100 kB\n");
        self::assertNull($this->memory()->available(), 'No MemAvailable (a kernel before 3.14).');

        $this->memory()->ensureRoomToBuild();
    }

    public function testTheServersOwnContainersAreTheLastTheKernelStops(): void
    {
        /** @var array{services: array<string, array{oom_score_adj?: int, environment?: array<string, string>}>} $compose */
        $compose = Yaml::parseFile(\dirname(__DIR__, 2).'/compose.yaml');
        $scores = array_map(static fn (array $service): mixed => $service['oom_score_adj'] ?? null, $compose['services']);

        self::assertSame(-900, $scores['database'], 'PostgreSQL last: one backend stopped takes every site\'s database through recovery.');
        self::assertSame([-500, -500, -500], [$scores['front'], $scores['agent'], $scores['gate']], 'Then the front door, the agent and the gate.');
        self::assertSame('${QUIRE_SERVER_BUILD_MEMORY_MB:-1536}', $compose['services']['agent']['environment']['QUIRE_SERVER_BUILD_MEMORY_MB'] ?? null);
    }

    private function machine(int $totalKilobytes, int $availableKilobytes): void
    {
        new Filesystem()->dumpFile($this->meminfo, "MemTotal:       {$totalKilobytes} kB\nMemFree:          123456 kB\nMemAvailable:   {$availableKilobytes} kB\nBuffers:           1234 kB\n");
    }

    private function memory(): Memory
    {
        return new Memory(Memory::BUILD_MEGABYTES, $this->meminfo);
    }
}
