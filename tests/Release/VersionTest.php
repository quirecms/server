<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Release;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Release\Version;

final class VersionTest extends TestCase
{
    /**
     * @return iterable<array{string, string, int}>
     */
    public static function orders(): iterable
    {
        yield ['0.1.0', '0.1.0', 0];
        yield ['0.1.1', '0.1.0', 1];
        yield ['0.2.0', '0.1.9', 1];
        yield ['0.10.0', '0.9.0', 1];
        yield ['1.0.0', '0.99.99', 1];
        yield ['0.2.0-rc.1', '0.2.0', -1];
        yield ['0.2.0-rc.2', '0.2.0-rc.1', 1];
        yield ['0.2.0-rc.10', '0.2.0-rc.9', 1];
        yield ['0.2.0-beta', '0.2.0-alpha', 1];
        yield ['0.2.0-rc.1', '0.2.0-rc', 1];
        yield ['0.2.0-1', '0.2.0-alpha', -1];
        yield ['0.2.0-rc.1', '0.1.9', 1];
    }

    #[DataProvider('orders')]
    public function testVersionsAreOrderedAsSemanticVersioningSays(string $a, string $b, int $order): void
    {
        self::assertSame($order, new Version($a)->compare(new Version($b)));
        self::assertSame(-$order, new Version($b)->compare(new Version($a)));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function notVersions(): iterable
    {
        yield [''];
        yield ['dev'];
        yield ['v0.1.0'];
        yield ['0.1'];
        yield ['01.1.0'];
        yield ['0.1.0+build'];
        yield ['0.1.0-'];
        yield ['0.1.0-rc..1'];
        yield ['0.1.0/../../x'];
        yield ['0.1.0 '];
        yield ["0.1.0\n"];
        yield ['0.1.0-rc;rm'];
        yield ['9999999.0.0'];
    }

    #[DataProvider('notVersions')]
    public function testOnlyVersionsAreAccepted(string $value): void
    {
        self::assertNull(Version::tryFrom($value));
        $this->expectException(\InvalidArgumentException::class);
        new Version($value);
    }

    public function testItSaysWhetherItIsAPreRelease(): void
    {
        self::assertTrue(new Version('0.2.0-rc.1')->isPreRelease());
        self::assertFalse(new Version('0.2.0')->isPreRelease());
        self::assertTrue(new Version('0.2.0')->isNewerThan(new Version('0.2.0-rc.1')));
    }
}
