<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Network;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Network\Ipv4Cidr;

final class Ipv4CidrTest extends TestCase
{
    public function testANetworkItsSizeAndAddresses(): void
    {
        $cidr = Ipv4Cidr::parse('10.72.0.64/27');

        self::assertSame('10.72.0.64/27', (string) $cidr);
        self::assertSame(32, $cidr->size());
        self::assertSame('10.72.0.66', $cidr->address(2));
        self::assertSame('10.72.0.95', long2ip($cidr->last()));
        self::assertTrue($cidr->isPrivate());
        self::assertFalse(Ipv4Cidr::parse('100.64.0.0/10')->isPrivate(), 'Shared address space (Tailscale) isn\'t private.');
        self::assertFalse(Ipv4Cidr::parse('172.32.0.0/16')->isPrivate(), 'Just past 172.16.0.0/12.');
    }

    public function testOverlapsAndContains(): void
    {
        $range = Ipv4Cidr::parse('10.72.0.0/16');

        self::assertTrue($range->contains(Ipv4Cidr::parse('10.72.255.192/26')));
        self::assertFalse($range->contains(Ipv4Cidr::parse('10.73.0.0/26')));
        self::assertTrue($range->overlaps(Ipv4Cidr::parse('10.0.0.0/8')), 'A wider network around it.');
        self::assertTrue(Ipv4Cidr::parse('10.72.0.0/26')->overlaps(Ipv4Cidr::parse('10.72.0.32/27')));
        self::assertFalse(Ipv4Cidr::parse('10.72.0.0/26')->overlaps(Ipv4Cidr::parse('10.72.0.64/26')), 'Side by side.');
    }

    #[DataProvider('notNetworks')]
    public function testOnlyANetworksOwnAddressInCidrNotation(string $value, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);
        Ipv4Cidr::parse($value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notNetworks(): iterable
    {
        yield 'an address inside it' => ['10.72.0.5/16', 'it would be "10.72.0.0/16"'];
        yield 'no prefix' => ['10.72.0.0', 'isn\'t an IPv4 network'];
        yield 'a prefix too long' => ['10.72.0.0/33', 'isn\'t an IPv4 network'];
        yield 'not an address' => ['10.72.0.256/24', 'isn\'t an IPv4 network'];
        yield 'IPv6' => ['fd00::/64', 'isn\'t an IPv4 network'];
        yield 'with spaces' => [' 10.72.0.0/16', 'isn\'t an IPv4 network'];
        yield 'with a line break after it' => ["10.72.0.0/16\n", 'isn\'t an IPv4 network'];
    }

    public function testOutsideItHasNoAddress(): void
    {
        $this->expectException(\OutOfRangeException::class);
        Ipv4Cidr::parse('10.72.0.0/27')->address(32);
    }
}
