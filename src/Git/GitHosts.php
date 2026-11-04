<?php

declare(strict_types=1);

namespace Quire\Server\Git;

/**
 * Where a repository's host is, checked right before Git connects to it (ISO-12): every address its
 * name has now must be on the internet, not this machine's or its networks' (a public name can point
 * anywhere, cloud metadata included). Git is then pinned to the address checked, so a second answer
 * can't send it elsewhere.
 *
 * A Git server on the owner's own network (a self-hosted Gitea in the same VPC) is allowed by name,
 * in server.env: QUIRE_SERVER_GIT_PRIVATE_HOSTS=git.internal.example,… Its addresses are still pinned.
 */
final readonly class GitHosts
{
    /** @var list<string> */
    private array $private;

    /** @var \Closure(string): list<string> */
    private \Closure $resolve;

    /**
     * @param string                                $private Host names allowed to be on private addresses, comma-separated
     * @param (\Closure(string): list<string>)|null $resolve A name's addresses (DNS, by default)
     */
    public function __construct(string $private = '', ?\Closure $resolve = null)
    {
        $this->private = array_values(array_filter(array_map(static fn (string $host): string => strtolower(trim($host)), explode(',', $private)), static fn (string $host): bool => '' !== $host));
        $this->resolve = $resolve ?? $this->dns(...);
    }

    /**
     * The address Git is to connect to for this host.
     *
     * @throws \DomainException When the name has no address, or one Git mustn't connect to
     */
    public function address(string $host): string
    {
        $host = strtolower($host);

        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return $host;
        }

        $addresses = ($this->resolve)($host);

        if ([] === $addresses) {
            throw new \DomainException(\sprintf('The repository\'s host "%s" has no address (DNS).', $host));
        }

        if (!\in_array($host, $this->private, true)) {
            foreach ($addresses as $address) {
                if (false === filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
                    throw new \DomainException(\sprintf('The repository\'s host "%s" is at %s, on this machine or a private network: a repository is on a host on the internet. (A Git server on your own network: add its name to QUIRE_SERVER_GIT_PRIVATE_HOSTS in server.env.)', $host, $address));
                }
            }
        }

        // IPv4 first: a machine without an IPv6 route is common, the other way round isn't
        usort($addresses, static fn (string $a, string $b): int => str_contains($a, ':') <=> str_contains($b, ':'));

        return $addresses[0];
    }

    /**
     * @return list<string>
     */
    private function dns(string $host): array
    {
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        $addresses = [];

        foreach (\is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (\is_string($address) && false !== filter_var($address, \FILTER_VALIDATE_IP)) {
                $addresses[] = $address;
            }
        }

        // A name the machine's own hosts file gives (DNS doesn't see it)
        if ([] === $addresses) {
            $addresses = array_values(array_filter(false === ($found = gethostbynamel($host)) ? [] : $found, static fn (string $address): bool => false !== filter_var($address, \FILTER_VALIDATE_IP)));
        }

        return array_values(array_unique($addresses));
    }
}
