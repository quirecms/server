<?php

declare(strict_types=1);

namespace Quire\Server\Network;

use Quire\Server\Process\ProcessRunner;
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Site\SiteFiles;
use Quire\Server\Site\SiteName;

/**
 * Each site's link (D-34): a network of its own, "quire-link-<project>", between the site, the front
 * door and PostgreSQL, and nothing else. Separate bridges don't reach each other, so a site reaches no
 * other site; it's internal, with no way out (the site's own network has that). The agent makes it on
 * its own socket, before the site's Compose joins it (as an external network, which only the agent can
 * create), and connects the front door (.2) and PostgreSQL (.3, as "quire-database") at their fixed
 * addresses: the site trusts exactly the front door's as its proxy.
 */
final readonly class SiteLinks
{
    public const string PREFIX = 'quire-link-';
    public const string LABEL = 'com.quirecms.server.link';

    /** The server's containers on a site's link (its Compose project is "quire-server") */
    public const string FRONT_DOOR = 'quire-server-front-1';
    public const string DATABASE = 'quire-server-database-1';

    public function __construct(
        private SiteNetworks $networks,
        private SiteNetworkRange $range,
        private DockerSubnets $docker,
        private ProcessRunner $runner,
        private string $dockerHost,
    ) {
    }

    public static function name(string $project): string
    {
        return self::PREFIX.$project;
    }

    /**
     * The site's addresses, its link made and the server's containers on it: what's missing is done,
     * what's there is checked. The front door and PostgreSQL are connected when their containers
     * exist; when they're made later, the server's Compose files connect them.
     *
     * @param bool $database Whether it uses the server's PostgreSQL (an adopted site keeps its own)
     *
     * @throws \RuntimeException When its link exists with other addresses than the site's
     */
    public function ensure(SiteName $site, string $project, bool $database): SiteSubnets
    {
        $link = self::name($project);
        $docker = $this->docker->all();
        $existing = $docker[$link] ?? null;
        // Its own networks don't stand in its way
        unset($docker[$link], $docker[$project.'_default']);
        $subnets = $this->networks->allocate($site, $this->range, $docker);

        if (null === $existing) {
            $created = $this->docker([
                'network', 'create', '--driver', 'bridge', '--internal',
                '--subnet', (string) $subnets->link, '--gateway', $subnets->link->address(1),
                '--label', self::LABEL.'='.$project, $link,
            ]);

            // Another run may have just made it
            if (!$created->successful() && !str_contains($created->errorOutput, 'already exists')) {
                $created->orFail(\sprintf('Making the site\'s link (%s, %s)', $link, $subnets->link));
            }
        } elseif ([(string) $subnets->link] !== array_map(strval(...), $existing)) {
            $has = [] === $existing ? 'none' : implode(', ', array_map(strval(...), $existing));

            throw new \RuntimeException(\sprintf('The network "%1$s" has the addresses %2$s, not the site\'s (%3$s): remove it ("docker network rm %1$s") and try again.', $link, $has, $subnets->link));
        }

        $this->connect($link, self::FRONT_DOOR, $subnets->frontDoor(), []);

        if ($database) {
            $this->connect($link, self::DATABASE, $subnets->database(), [SiteFiles::DATABASE_HOST]);
        } else {
            $this->disconnect($link, self::DATABASE);
        }

        return $subnets;
    }

    /**
     * The site's link gone and its addresses free (it's removed): the server's containers leave it first.
     */
    public function remove(SiteName $site, string $project): void
    {
        $link = self::name($project);

        if ($this->docker(['network', 'inspect', '--format', '{{.Name}}', $link])->successful()) {
            $this->disconnect($link, self::FRONT_DOOR);
            $this->disconnect($link, self::DATABASE);
            $this->docker(['network', 'rm', $link])->orFail(\sprintf('Removing the site\'s link (%s)', $link));
        }

        $this->networks->release($site);
    }

    /**
     * @param list<string> $aliases
     */
    private function connect(string $link, string $container, string $address, array $aliases): void
    {
        $networks = $this->docker(['container', 'inspect', '--format', '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}} {{end}}', $container]);

        // Not made yet: the server's Compose files connect it when it is
        if (!$networks->successful() || \in_array($link, explode(' ', trim($networks->output)), true)) {
            return;
        }

        $aliasArguments = array_merge(...array_map(static fn (string $alias): array => ['--alias', $alias], $aliases));
        $this->docker(['network', 'connect', '--ip', $address, ...$aliasArguments, $link, $container])->orFail(\sprintf('Connecting %s to the site\'s link', $container));
    }

    private function disconnect(string $link, string $container): void
    {
        $networks = $this->docker(['container', 'inspect', '--format', '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}} {{end}}', $container]);

        if ($networks->successful() && \in_array($link, explode(' ', trim($networks->output)), true)) {
            $this->docker(['network', 'disconnect', '--force', $link, $container])->orFail(\sprintf('Disconnecting %s from the site\'s link', $container));
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function docker(array $arguments): \Quire\Server\Process\ProcessResult
    {
        return $this->runner->run(['docker', ...$arguments], ['DOCKER_HOST' => $this->dockerHost], timeout: 60);
    }
}
