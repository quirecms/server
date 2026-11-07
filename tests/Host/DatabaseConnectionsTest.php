<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Host;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * bin/database-connections.sh, as the installer and "quire-server up" run it: PostgreSQL's connections
 * sized from the machine's memory (D-39), kept in server.env.
 */
final class DatabaseConnectionsTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-database-connections-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/server/server.env', "QUIRE_SERVER_APP_SECRET=x\nQUIRE_SERVER_DATABASE_CONNECTIONS=\nQUIRE_SERVER_UPDATE_HOUR=3\n");
        new Filesystem()->chmod($this->root.'/server/server.env', 0o600);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function machines(): iterable
    {
        // MemTotal as the kernel says it: a little under the size sold
        yield '1 GB' => [1_004_000, '200'];
        yield '4 GB (the demo)' => [4_005_000, '200'];
        yield '8 GB' => [8_139_000, '200'];
        yield '16 GB' => [16_370_000, '257'];
        yield '32 GB (the scale test)' => [32_859_000, '500'];
        yield '64 GB' => [65_840_000, '983'];
        yield '128 GB' => [131_900_000, '1000'];
    }

    #[DataProvider('machines')]
    public function testItsSizedFromTheMachinesMemory(int $kilobytes, string $connections): void
    {
        self::assertSame($connections."\n", $this->shell('database_connections "$ROOT/meminfo"', $kilobytes));
    }

    public function testWithoutAMemoryLineItsTheOldFixedValue(): void
    {
        new Filesystem()->dumpFile($this->root.'/meminfo', "MemFree: 1000 kB\n");

        self::assertSame("200\n", $this->shell('database_connections "$ROOT/meminfo"'));
    }

    public function testItsWrittenOnceAndSaidOnlyWhenPostgresStartsAgainWithAnother(): void
    {
        // 4 GB: the value PostgreSQL had without one, so nothing is said
        self::assertSame('', $this->shell('database_connections_sync "$ROOT/server" "$ROOT/meminfo"', 4_005_000));
        self::assertSame("QUIRE_SERVER_APP_SECRET=x\nQUIRE_SERVER_UPDATE_HOUR=3\nQUIRE_SERVER_DATABASE_CONNECTIONS=200\n", file_get_contents($this->root.'/server/server.env'), 'Every other line kept.');
        self::assertSame(0o600, fileperms($this->root.'/server/server.env') & 0o777, 'Still for root only: it holds secrets.');
        self::assertSame('', $this->shell('database_connections_sync "$ROOT/server" "$ROOT/meminfo"', 4_005_000), 'Unchanged: nothing written or said.');

        // The machine was resized
        self::assertSame(
            "PostgreSQL takes 500 connections from now on, not 200 (sized from this machine's memory): it starts again, every site offline for a few seconds.\n",
            $this->shell('database_connections_sync "$ROOT/server" "$ROOT/meminfo"', 32_859_000),
        );
        self::assertSame("500\n", $this->shell('database_connections_set "$ROOT/server"'));
    }

    public function testAServerFromBeforeD39IsToldWhenItsValueChanges(): void
    {
        new Filesystem()->dumpFile($this->root.'/server/server.env', "QUIRE_SERVER_APP_SECRET=x\n");

        self::assertStringContainsString('500 connections from now on, not 200', $this->shell('database_connections_sync "$ROOT/server" "$ROOT/meminfo"', 32_859_000), 'Its PostgreSQL ran with compose.yaml\'s 200.');
        self::assertStringEndsWith("QUIRE_SERVER_DATABASE_CONNECTIONS=500\n", (string) file_get_contents($this->root.'/server/server.env'));
    }

    public function testComposeTakesTheValueFromServerEnvWithTheOldOneAsDefault(): void
    {
        self::assertStringContainsString('"max_connections=${QUIRE_SERVER_DATABASE_CONNECTIONS:-200}"', (string) file_get_contents(\dirname(__DIR__, 2).'/compose.yaml'));
        self::assertMatchesRegularExpression('/^QUIRE_SERVER_DATABASE_CONNECTIONS=$/m', (string) file_get_contents(\dirname(__DIR__, 2).'/server.env.dist'));
    }

    private function shell(string $script, ?int $kilobytes = null): string
    {
        if (null !== $kilobytes) {
            new Filesystem()->dumpFile($this->root.'/meminfo', "MemTotal:       {$kilobytes} kB\nMemFree:        1000 kB\n");
        }

        $process = new Process(['sh', '-c', '. "$SCRIPT"; '.$script], null, ['SCRIPT' => \dirname(__DIR__, 2).'/bin/database-connections.sh', 'ROOT' => $this->root, 'PATH' => '/usr/bin:/bin']);
        $process->mustRun();

        return $process->getOutput();
    }
}
