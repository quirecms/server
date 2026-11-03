<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Doctrine\DBAL\Connection;
use Quire\Server\Network\Ipv4Cidr;
use Quire\Server\Network\SiteNetworkRange;
use Quire\Server\Network\SiteSubnets;
use Quire\Server\Site\SiteName;

/**
 * Which addresses each site has (D-34), kept in the registry so a site keeps them across restarts and
 * restores. A site gets the first /26 of the range that no other site has and no Docker network on the
 * machine overlaps; PostgreSQL refuses two sites' that overlap, whatever runs at once.
 */
final readonly class SiteNetworks
{
    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    public function find(SiteName $site): ?SiteSubnets
    {
        $block = $this->connection->fetchOne(\sprintf('SELECT block FROM %s.site_network WHERE site = ?', $this->schema()), [$site->value]);

        return \is_string($block) ? new SiteSubnets(Ipv4Cidr::parse($block)) : null;
    }

    /**
     * The site's addresses: those it has, or new ones.
     *
     * @param array<string, list<Ipv4Cidr>> $docker Docker's networks' subnets, by name (DockerSubnets)
     *
     * @throws \DomainException When the range has no room left that nothing overlaps
     */
    public function allocate(SiteName $site, SiteNetworkRange $range, array $docker): SiteSubnets
    {
        return $this->connection->transactional(function () use ($site, $range, $docker): SiteSubnets {
            // One allocation at a time: two sites never pick the same free block
            $this->connection->executeStatement(\sprintf('LOCK TABLE %s.site_network IN SHARE ROW EXCLUSIVE MODE', $this->schema()));

            if (($had = $this->find($site)) instanceof SiteSubnets) {
                return $had;
            }

            $taken = array_map(static fn (mixed $block): Ipv4Cidr => Ipv4Cidr::parse(\is_string($block) ? $block : ''), $this->connection->fetchFirstColumn(\sprintf('SELECT block FROM %s.site_network', $this->schema())));
            $inDocker = array_merge(...array_values($docker));
            $blockedByDocker = 0;

            for ($index = 0; $index < $range->capacity(); ++$index) {
                $subnets = $range->block($index);

                if ([] !== array_filter($taken, static fn (Ipv4Cidr $other): bool => $other->overlaps($subnets->block))) {
                    continue;
                }

                if ([] !== array_filter($inDocker, static fn (Ipv4Cidr $other): bool => $other->overlaps($subnets->block))) {
                    ++$blockedByDocker;

                    continue;
                }

                $this->connection->insert($this->table(), ['site' => $site->value, 'block' => (string) $subnets->block]);

                return $subnets;
            }

            throw new \DomainException(\sprintf('No addresses are left for a site in %s (QUIRE_SERVER_SITE_NETWORKS): %d sites have theirs%s. A wider range that holds this one gives room (sites keep the addresses they have): see "Site networks" in the README.', $range->cidr, \count($taken), $blockedByDocker > 0 ? \sprintf(', and other Docker networks on this machine overlap %d more (%s)', $blockedByDocker, implode(', ', $this->overlapping($docker, $range->cidr))) : ''));
        });
    }

    /**
     * Every site's addresses, by site name.
     *
     * @return array<string, SiteSubnets>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->connection->fetchAllKeyValue(\sprintf('SELECT site, block FROM %s.site_network ORDER BY block', $this->schema())) as $site => $block) {
            $all[(string) $site] = new SiteSubnets(Ipv4Cidr::parse(\is_string($block) ? $block : ''));
        }

        return $all;
    }

    /** The site's addresses are free again (it's removed) */
    public function release(SiteName $site): void
    {
        $this->connection->delete($this->table(), ['site' => $site->value]);
    }

    /**
     * @param array<string, list<Ipv4Cidr>> $docker
     *
     * @return list<string>
     */
    private function overlapping(array $docker, Ipv4Cidr $range): array
    {
        $names = [];

        foreach ($docker as $name => $subnets) {
            foreach ($subnets as $subnet) {
                if ($subnet->overlaps($range)) {
                    $names[] = $name.' '.$subnet;
                }
            }
        }

        return $names;
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }

    private function table(): string
    {
        return $this->schema().'.site_network';
    }
}
