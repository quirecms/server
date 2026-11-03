<?php

declare(strict_types=1);

namespace Quire\Server\Network;

use Quire\Server\Database\DatabaseAccess;
use Quire\Server\Process\ProcessRunner;
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Site\Site;
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
        private DatabaseAccess $access,
    ) {
    }

    public static function name(string $project): string
    {
        return self::PREFIX.$project;
    }

    /**
     * The site's addresses, its link made and the server's containers on it: what's missing is done,
     * what's there is checked. The front door and PostgreSQL are connected when their containers
     * exist; when they're made later, reconcile() connects them.
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

        // Its role logs in from there, and from nowhere else, before anything of the site runs
        $this->access->sites();

        return $subnets;
    }

    /**
     * Every site's link made and the server's containers on it, as they should be: a container keeps its
     * networks across restarts, but not when it's made again (an update, "quire-server up" after a
     * change), and the server's Compose files don't name sites' links. Run in each of the agent's rounds
     * and after "quire-server up": three calls to Docker when nothing is missing, whatever the number of
     * sites. It only adds: an operation on a site may be making its link at the same time.
     *
     * @param list<Site> $sites
     *
     * @return list<string> What it did, one line each
     */
    public function reconcile(array $sites): array
    {
        $allocated = $this->networks->all();
        $networks = $this->docker->all();
        $front = $this->attached(self::FRONT_DOOR);
        $database = $this->attached(self::DATABASE);
        $done = [];

        foreach ($sites as $site) {
            if (Site::REMOVED === $site->status || !isset($allocated[$site->name->value])) {
                continue;
            }

            $link = self::name($site->project);
            $shared = Site::SHARED_DATABASE === $site->databaseMode;
            $missing = array_keys(array_filter([
                'the link itself' => !isset($networks[$link]),
                'the front door' => null !== $front && !\in_array($link, $front, true),
                'PostgreSQL' => $shared && null !== $database && !\in_array($link, $database, true),
            ]));

            if ([] !== $missing) {
                $this->ensure($site->name, $site->project, $shared);
                $done[] = \sprintf('%s: %s back on its link', $site->name, implode(', ', $missing));
            }
        }

        // PostgreSQL's rules as the registry has them (the server's network may have been made again)
        if ($this->access->server()) {
            $done[] = 'the server\'s logins to PostgreSQL from its network written again';
        }

        if ($this->access->sites()) {
            $done[] = 'sites\' logins to PostgreSQL from their links written again';
        }

        return $done;
    }

    /**
     * Whether the front door reaches a site's web container on its link, by the name it proxies to
     * ("<domain>.quire.internal", port 80): null when the front door isn't there to ask.
     */
    public function frontDoorReaches(string $domain): ?bool
    {
        $reached = $this->docker(['exec', self::FRONT_DOOR, 'nc', '-z', '-w', '5', $domain.SiteFiles::EDGE_SUFFIX, '80']);

        if (!$reached->successful() && (str_contains($reached->errorOutput, 'No such container') || str_contains($reached->errorOutput, 'is not running'))) {
            return null;
        }

        return $reached->successful();
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
        $this->access->sites();
    }

    /**
     * @param list<string> $aliases
     */
    private function connect(string $link, string $container, string $address, array $aliases): void
    {
        $networks = $this->attached($container);

        // Not made yet (the agent's round connects it once it is), or on it already
        if (null === $networks || \in_array($link, $networks, true)) {
            return;
        }

        $aliasArguments = array_merge(...array_map(static fn (string $alias): array => ['--alias', $alias], $aliases));
        $connected = $this->docker(['network', 'connect', '--ip', $address, ...$aliasArguments, $link, $container]);

        // Connected meanwhile (the agent's round and an operation on the site, at once): done
        if (!$connected->successful() && !str_contains($connected->errorOutput, 'already exists')) {
            $connected->orFail(\sprintf('Connecting %s to the site\'s link', $container));
        }
    }

    /**
     * @return list<string>|null The networks a container is on, or null when it isn't there
     */
    private function attached(string $container): ?array
    {
        $networks = $this->docker(['container', 'inspect', '--format', '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}} {{end}}', $container]);

        return $networks->successful() ? array_values(array_filter(explode(' ', trim($networks->output)), static fn (string $name): bool => '' !== $name)) : null;
    }

    private function disconnect(string $link, string $container): void
    {
        if (\in_array($link, $this->attached($container) ?? [], true)) {
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
