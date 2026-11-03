<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Network;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Network\SiteNetworkRange;

final class SiteNetworkRangeTest extends TestCase
{
    public function testTheDefaultHoldsAThousandSitesEachWithItsLinkAndItsOwnNetwork(): void
    {
        $range = new SiteNetworkRange(SiteNetworkRange::DEFAULT);

        self::assertSame(1024, $range->capacity());

        $first = $range->block(0);
        self::assertSame(['10.72.0.0/26', '10.72.0.0/27', '10.72.0.32/27'], [(string) $first->block, (string) $first->link, (string) $first->project]);
        self::assertSame(['10.72.0.2', '10.72.0.3'], [$first->frontDoor(), $first->database()], 'Docker\'s gateway has .1.');

        $last = $range->block(1023);
        self::assertSame(['10.72.255.192/27', '10.72.255.224/27'], [(string) $last->link, (string) $last->project]);
    }

    public function testThereIsNoPlacePastItsEnd(): void
    {
        $this->expectException(\OutOfRangeException::class);
        new SiteNetworkRange('10.72.0.0/22')->block(16);
    }

    #[DataProvider('notRanges')]
    public function testOnlyAPrivateRangeOfAFittingSize(string $range, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);
        new SiteNetworkRange($range);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notRanges(): iterable
    {
        yield 'public' => ['8.8.0.0/16', 'isn\'t a private range'];
        yield 'shared address space' => ['100.64.0.0/16', 'isn\'t a private range'];
        yield 'too small for 16 sites' => ['10.72.0.0/23', 'a range from /8 to /22 is needed'];
        yield 'not a network\'s own address' => ['10.72.1.0/16', 'QUIRE_SERVER_SITE_NETWORKS: "10.72.1.0/16" isn\'t a network\'s own address'];
        yield 'empty' => ['', 'QUIRE_SERVER_SITE_NETWORKS: "" isn\'t an IPv4 network'];
    }
}
