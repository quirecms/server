<?php

declare(strict_types=1);

namespace Quire\Server\Network;

/**
 * A site's addresses: a /26 of the server's range (QUIRE_SERVER_SITE_NETWORKS), in two /27s.
 *
 *   - Its link (quire-link-<project>): the site's php and worker, the front door and PostgreSQL. No way
 *     out. Docker's gateway is .1, the front door .2, PostgreSQL .3; the site's containers get .16 to .31.
 *   - Its project's default network: the site's own containers and its way out to the internet, at an
 *     address the server planned instead of one from Docker's pools (which run out at ~30 networks).
 */
final readonly class SiteSubnets
{
    public const int PREFIX = 26;

    public Ipv4Cidr $link;
    public Ipv4Cidr $project;

    public function __construct(
        public Ipv4Cidr $block,
    ) {
        if (self::PREFIX !== $block->prefix) {
            throw new \InvalidArgumentException(\sprintf('A site\'s addresses are a /%d, not %s.', self::PREFIX, $block));
        }

        $this->link = Ipv4Cidr::of($block->network, self::PREFIX + 1);
        $this->project = Ipv4Cidr::of($block->network + $this->link->size(), self::PREFIX + 1);
    }

    /** The front door's address on the site's link: the only proxy the site trusts */
    public function frontDoor(): string
    {
        return $this->link->address(2);
    }

    /** PostgreSQL's address on the site's link */
    public function database(): string
    {
        return $this->link->address(3);
    }

    /**
     * Where Docker gives the site's containers their addresses on its link: its upper half, so none
     * takes the front door's or PostgreSQL's while they're off it (made again, not reconnected yet).
     */
    public function linkContainers(): Ipv4Cidr
    {
        return Ipv4Cidr::of($this->link->network + intdiv($this->link->size(), 2), $this->link->prefix + 1);
    }
}
