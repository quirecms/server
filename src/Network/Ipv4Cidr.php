<?php

declare(strict_types=1);

namespace Quire\Server\Network;

/**
 * An IPv4 network in CIDR notation, e.g. "10.72.0.0/16": its first address and its prefix length.
 * Only the network's own address is accepted ("10.72.0.5/16" is refused, not rounded down), so what's
 * written is what's meant.
 */
final readonly class Ipv4Cidr implements \Stringable
{
    private function __construct(
        /** The first address, as an unsigned 32-bit number */
        public int $network,
        public int $prefix,
    ) {
    }

    /**
     * @throws \InvalidArgumentException When it isn't an IPv4 network in CIDR notation
     */
    public static function parse(string $value): self
    {
        if (1 !== preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})\z/', $value, $match) || false === ($address = ip2long($match[1])) || (int) $match[2] > 32) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t an IPv4 network in CIDR notation, e.g. "10.72.0.0/16".', $value));
        }

        $cidr = new self($address, (int) $match[2]);

        if ($cidr->network !== ($address & $cidr->mask())) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a network\'s own address: it would be "%s".', $value, long2ip($address & $cidr->mask()).'/'.$match[2]));
        }

        return $cidr;
    }

    public static function of(int $network, int $prefix): self
    {
        return self::parse(long2ip($network).'/'.$prefix);
    }

    /** How many addresses it holds */
    public function size(): int
    {
        return 1 << (32 - $this->prefix);
    }

    /** The last address, as a number */
    public function last(): int
    {
        return $this->network + $this->size() - 1;
    }

    public function overlaps(self $other): bool
    {
        return $this->network <= $other->last() && $other->network <= $this->last();
    }

    public function contains(self $other): bool
    {
        return $this->network <= $other->network && $other->last() <= $this->last();
    }

    /**
     * Its address at an offset from the first, e.g. 2 for "10.72.0.2" in "10.72.0.0/27".
     */
    public function address(int $offset): string
    {
        if ($offset < 0 || $offset >= $this->size()) {
            throw new \OutOfRangeException(\sprintf('%s has no address at offset %d.', $this, $offset));
        }

        return long2ip($this->network + $offset);
    }

    /** Whether it's within the private ranges of RFC 1918 (10/8, 172.16/12, 192.168/16) */
    public function isPrivate(): bool
    {
        return array_any(['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'], fn (string $private): bool => self::parse($private)->contains($this));
    }

    #[\Override]
    public function __toString(): string
    {
        return long2ip($this->network).'/'.$this->prefix;
    }

    private function mask(): int
    {
        return 0 === $this->prefix ? 0 : (0xFFFFFFFF << (32 - $this->prefix)) & 0xFFFFFFFF;
    }
}
