<?php

declare(strict_types=1);

namespace Quire\Server\Network;

use Quire\Server\Process\ProcessRunner;

/**
 * The IPv4 subnets of Docker's networks on this machine, every project's: Docker doesn't check an
 * explicit subnet against the host's routes, and refuses one that overlaps another network only when
 * it's created, so the server checks first and says which network is in the way.
 */
final readonly class DockerSubnets
{
    public function __construct(
        private ProcessRunner $runner,
        private string $dockerHost,
    ) {
    }

    /**
     * @return array<string, list<Ipv4Cidr>> By network name
     */
    public function all(): array
    {
        $env = ['DOCKER_HOST' => $this->dockerHost];
        $ids = array_values(array_filter(explode("\n", trim($this->runner->run(['docker', 'network', 'ls', '--quiet', '--no-trunc'], $env, timeout: 60)->orFail('Listing Docker\'s networks')->output)), static fn (string $id): bool => '' !== $id));

        if ([] === $ids) {
            return [];
        }

        $inspected = $this->runner->run(['docker', 'network', 'inspect', '--format', '{{.Name}}{{range .IPAM.Config}} {{.Subnet}}{{end}}', ...$ids], $env, timeout: 60)->orFail('Reading Docker\'s networks');
        $subnets = [];

        foreach (array_filter(explode("\n", trim($inspected->output)), static fn (string $line): bool => '' !== trim($line)) as $line) {
            $fields = explode(' ', trim($line));
            $name = array_shift($fields);
            // IPv6 subnets (and anything that isn't an IPv4 network) aren't what sites' addresses can meet
            $subnets[$name] = array_values(array_filter(array_map(static function (string $subnet): ?Ipv4Cidr {
                try {
                    return Ipv4Cidr::parse($subnet);
                } catch (\InvalidArgumentException) {
                    return null;
                }
            }, $fields)));
        }

        return $subnets;
    }
}
