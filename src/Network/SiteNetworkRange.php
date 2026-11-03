<?php

declare(strict_types=1);

namespace Quire\Server\Network;

/**
 * The addresses the server hands its sites (QUIRE_SERVER_SITE_NETWORKS, 10.72.0.0/16 by default): a
 * private range, cut into /26s, one per site. A /16 holds 1,024 sites.
 *
 * 10.72.0.0/16 is in RFC 1918 space, away from what clouds, VPNs, Kubernetes, Podman and Docker's own
 * pools use (the table in the server's docs). No range is free everywhere, so it's checked where it
 * runs: against Docker's networks at each allocation, and the host's routes by the installer.
 */
final readonly class SiteNetworkRange
{
    public const string DEFAULT = '10.72.0.0/16';

    /** At most a /8, at least room for 16 sites */
    private const int WIDEST = 8;
    private const int NARROWEST = 22;

    public Ipv4Cidr $cidr;

    /**
     * @throws \InvalidArgumentException When it isn't a private IPv4 network of a fitting size
     */
    public function __construct(string $range)
    {
        try {
            $cidr = Ipv4Cidr::parse(trim($range));
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('QUIRE_SERVER_SITE_NETWORKS: '.$e->getMessage(), $e->getCode(), previous: $e);
        }

        if (!$cidr->isPrivate()) {
            throw new \InvalidArgumentException(\sprintf('QUIRE_SERVER_SITE_NETWORKS: %s isn\'t a private range (RFC 1918: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16).', $cidr));
        }

        if ($cidr->prefix < self::WIDEST || $cidr->prefix > self::NARROWEST) {
            throw new \InvalidArgumentException(\sprintf('QUIRE_SERVER_SITE_NETWORKS: %s is a /%d; a range from /%d to /%d is needed (a /16 holds 1,024 sites).', $cidr, $cidr->prefix, self::WIDEST, self::NARROWEST));
        }

        $this->cidr = $cidr;
    }

    /** How many sites it holds */
    public function capacity(): int
    {
        return 1 << (SiteSubnets::PREFIX - $this->cidr->prefix);
    }

    /**
     * The addresses of the site at this place in the range, from 0.
     */
    public function block(int $index): SiteSubnets
    {
        if ($index < 0 || $index >= $this->capacity()) {
            throw new \OutOfRangeException(\sprintf('%s holds %d sites; there\'s no place %d.', $this->cidr, $this->capacity(), $index));
        }

        return new SiteSubnets(Ipv4Cidr::of($this->cidr->network + $index * (1 << (32 - SiteSubnets::PREFIX)), SiteSubnets::PREFIX));
    }
}
