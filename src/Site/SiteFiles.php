<?php

declare(strict_types=1);

namespace Quire\Server\Site;

use Quire\Server\Docker\PolicyContext;
use Quire\Server\Network\SiteLinks;
use Quire\Server\Network\SiteSubnets;
use Quire\Server\Process\Durable;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * The files that make a skeleton project a site of this server, next to its own (which are never
 * changed): prod.env, its settings and secrets (readable by root only), and compose.server.yaml, how it
 * joins the server (no secrets; written again whenever its domains or settings change).
 */
final readonly class SiteFiles
{
    public const string ENV_FILE = 'prod.env';
    public const string OVERRIDE = 'compose.server.yaml';

    /** The server's PostgreSQL, by its name on each site's link (SiteLinks) */
    public const string DATABASE_HOST = 'quire-database';

    /**
     * How the front door names a site's domain on its link: "<domain>.quire.internal". The ".internal"
     * domain is never public (ICANN keeps it for private use): while the site is down, its name doesn't
     * resolve anywhere else, and visitors get the "Back in a moment" page. A site's public domain would
     * be asked of public DNS then, which names this server: the front door again, in a loop.
     */
    /**
     * A site that sends no email: written as such, not left out. Symfony takes a real environment
     * variable over the site's own .env files, so a MAILER_DSN left out is the one its code ships (a
     * Git mode site's, a backup's), which may be the original's (SEC-0014).
     */
    public const string NO_MAIL = 'null://null';

    public const string EDGE_SUFFIX = '.quire.internal';

    /**
     * The first label of a site's name on its link: "edge.<domain>.quire.internal". The Docker gate
     * gives an alias to the site whose prefix ("<project>-") it starts with (YorvoGate 0.3), and a site's
     * domain may start with another site's name ("jane-blog.example" next to the site "jane"). Every
     * prefix ends with "-" and no project's name has a "." (SiteName's pattern), so none covers "edge.":
     * not even a site called "edge", whose prefix is "edge-".
     */
    public const string EDGE_LABEL = 'edge.';

    /**
     * A site's name on its link, for one of its domains.
     */
    public static function edgeName(string $host): string
    {
        return self::EDGE_LABEL.$host.self::EDGE_SUFFIX;
    }

    /**
     * Its names on its link: "edge.<domain>.quire.internal", and the name releases before 0.6 gave it
     * ("<domain>.quire.internal"), which their front door asks for after an update rolls back, where the
     * gate allows it: not when another site's prefix covers it. Deprecated: 0.7 gives the first only.
     *
     * @param list<string> $projects Every other site's project
     *
     * @return list<string>
     */
    public static function edgeNames(string $host, array $projects): array
    {
        return [self::edgeName($host), ...(null === self::coveredBy($host, $projects) ? [$host.self::EDGE_SUFFIX] : [])];
    }

    /**
     * The other site whose prefix covers a domain's name of releases before 0.6, or null.
     *
     * @param list<string> $projects
     */
    public static function coveredBy(string $host, array $projects): ?string
    {
        foreach ($projects as $project) {
            if (str_starts_with($host, $project.'-')) {
                return $project;
            }
        }

        return null;
    }

    /**
     * The domain a name on a site's link is for (either form), or null when it's none of them.
     */
    public static function hostOfEdgeName(string $alias): ?string
    {
        if (!str_ends_with($alias, self::EDGE_SUFFIX)) {
            return null;
        }

        $host = substr($alias, 0, -\strlen(self::EDGE_SUFFIX));

        return str_starts_with($host, self::EDGE_LABEL) ? substr($host, \strlen(self::EDGE_LABEL)) : $host;
    }

    /**
     * FrankenPHP worker threads a site's php runs: 4 serve about 80 to 200 pages a second, plenty for a
     * site, and each holds one database connection (SiteDatabases::CONNECTIONS) while it's in use.
     */
    public const int PHP_THREADS = 4;

    /** What a server writes in a site's prod.env (its secrets a new site gets anew) */
    private const array SERVERS = ['COMPOSE_FILE', 'COMPOSE_PROJECT_NAME', 'SERVER_NAME', 'APP_SECRET', 'QUIRE_DATABASE_PASSWORD', 'POSTGRES_PASSWORD'];

    /** The skeleton's production setup, then the server's */
    private const string COMPOSE_FILE = 'compose.yaml:compose.prod.yaml:compose.server.yaml';

    /**
     * What a site's prod.env has that its owner wrote: everything but what a server writes (or, on
     * another server, writes anew), and what's not written as this server writes values.
     *
     * @return array<string, string>
     */
    public static function ownSettings(EnvFile $env): array
    {
        $own = [];

        foreach ($env->values() as $name => $value) {
            if (!\in_array($name, self::SERVERS, true) && 1 !== preg_match('/[\'\x00-\x1f\x7f]/', $value) && !str_ends_with($value, '\\')) {
                $own[$name] = $value;
            }
        }

        return $own;
    }

    /**
     * @param array<string, string> $settings E.g. APP_SECRET, MAILER_DSN, QUIRE_DATABASE_PASSWORD
     */
    public function writeEnv(string $directory, SiteName $site, Domain $primary, array $settings): void
    {
        $values = ['COMPOSE_FILE' => self::COMPOSE_FILE, 'COMPOSE_PROJECT_NAME' => $site->value, 'SERVER_NAME' => $primary->value] + $settings;
        $lines = ['# The site\'s settings and secrets on this server. Written by the Quire Server agent; keep it private.'];

        foreach ($values as $name => $value) {
            if (1 !== preg_match('/^[A-Z][A-Z0-9_]*\z/', $name)) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a variable name.', $name));
            }

            // Single quotes: taken as they are by Compose. So no quote and no line break in a value, and
            // none ending in "\" (Compose reads "\'" as an escaped quote).
            if (1 === preg_match('/[\'\x00-\x1f\x7f]/', $value) || str_ends_with($value, '\\')) {
                throw new \InvalidArgumentException(\sprintf('The value of %s can\'t contain quotes or control characters, or end with "\\".', $name));
            }

            $lines[] = \sprintf("%s='%s'", $name, $value);
        }

        $this->write($directory.'/'.self::ENV_FILE, implode("\n", $lines)."\n", 0o600);
    }

    /**
     * The image a site's web and worker containers run, built from its folder: named in its own
     * namespace, which no other project's can be.
     */
    public static function image(string $project): string
    {
        return PolicyContext::IMAGES.$project.'/php-prod';
    }

    /**
     * @param list<Domain> $domains  The primary one first
     * @param SiteSubnets  $subnets  Its addresses (SiteLinks::ensure made its link)
     * @param list<string> $projects Every other site's project, for its names on its link (edgeNames)
     * @param string|null  $project  Its Compose project (its own for an adopted site), the site's name if null
     */
    public function writeOverride(string $directory, SiteName $site, array $domains, string $databaseMode, SiteSubnets $subnets, array $projects, ?string $project = null): void
    {
        if ([] === $domains) {
            throw new \InvalidArgumentException('A site needs at least one domain.');
        }

        $hosts = array_map(static fn (Domain $domain): string => $domain->value, $domains);
        $primary = $hosts[0];
        $link = SiteLinks::name($project ?? $site->value);
        $hardening = ['security_opt' => ['no-new-privileges=true'], 'cap_drop' => ['ALL']];
        // Every container of the site comes back after a reboot or a crash: said here, whatever its own
        // files say (the skeleton's PostgreSQL had none, so an adopted site came back without it)
        $hardening['restart'] = 'unless-stopped';
        $php = [
            'image' => self::image($project ?? $site->value),
            // Only the front door reaches the site.
            'ports' => new TaggedValue('reset', null),
            'environment' => [
                // Plain HTTP for its domains: the front door has the certificates.
                'SERVER_NAME' => implode(', ', array_map(static fn (string $host): string => 'http://'.$host, $hosts)).', php:80',
                'DEFAULT_URI' => 'https://'.$primary,
                // The front door's address on the site's link, and nothing else: a proxy anywhere else
                // could claim any client address (M-08)
                'SYMFONY_TRUSTED_PROXIES' => $subnets->frontDoor(),
                // Its PHP threads, said rather than FrankenPHP's default (2 a CPU): each holds a
                // database connection and a booted Symfony, which would grow with the machine (D-39)
                'FRANKENPHP_WORKER_CONFIG' => 'num '.self::PHP_THREADS,
            ],
            'labels' => ['com.quirecms.site' => 'true', 'com.quirecms.server.site' => $site->value],
            // Its link, where the front door finds it by these names; its own network for the internet
            'networks' => ['default' => null, $link => ['aliases' => array_merge(...array_map(static fn (string $host): array => self::edgeNames($host, $projects), $hosts))]],
            'mem_limit' => '1g',
            'pids_limit' => 512,
            // FrankenPHP's binary needs this one to start (a file capability, for port 80).
            'cap_add' => ['NET_BIND_SERVICE'],
        ] + $hardening;
        $worker = [
            'image' => self::image($project ?? $site->value),
            'environment' => ['DEFAULT_URI' => 'https://'.$primary],
            'labels' => ['com.quirecms.server.site' => $site->value],
            'networks' => ['default' => null],
            'mem_limit' => '512m',
            'pids_limit' => 256,
        ] + $hardening;
        // The site's own PostgreSQL (an adopted site's): what its entrypoint needs to own its data and
        // become its user, and nothing else.
        $database = [
            'cap_add' => ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID'],
            'mem_limit' => '1g',
            'pids_limit' => 512,
        ] + $hardening;
        $services = ['php' => $php, 'worker' => $worker, 'database' => $database];

        $networks = [
            // Its own network at the addresses the server planned for it, not one of Docker's pools
            'default' => ['ipam' => ['config' => [['subnet' => (string) $subnets->project]]]],
            $link => ['external' => true],
        ];

        if (Site::SHARED_DATABASE === $databaseMode) {
            // The server's PostgreSQL, on the site's link; the site's own doesn't run.
            $services['worker']['networks'][$link] = null;
            $url = \sprintf('postgresql://%1$s:${QUIRE_DATABASE_PASSWORD}@%2$s:5432/%1$s?serverVersion=18&charset=utf8', $site->databaseIdentifier(), self::DATABASE_HOST);
            $services['php']['environment']['DATABASE_URL'] = $url;
            $services['worker']['environment']['DATABASE_URL'] = $url;
            // The site's own PostgreSQL doesn't run: the server's has its database.
            $services['php']['depends_on'] = new TaggedValue('reset', null);
            $services['database']['profiles'] = ['own-database'];
        }

        $yaml = "# How the site \"{$site->value}\" runs on this server. Written by the Quire Server agent and written\n# again when the site changes: don't edit it, it would be replaced.\n"
            .Yaml::dump(['services' => $services, 'networks' => $networks], 6, 2, Yaml::DUMP_NULL_AS_TILDE);

        $this->write($directory.'/'.self::OVERRIDE, $yaml, 0o644);
    }

    /**
     * Writes a file atomically (a temporary file, then renamed), with these permissions.
     */
    public function write(string $path, string $content, int $mode): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $previous = umask(0o077);

        try {
            if (false === file_put_contents($temporary, $content, \LOCK_EX)) {
                throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $path));
            }

            chmod($temporary, $mode);
            Durable::sync($temporary);

            if (!rename($temporary, $path)) {
                throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $path));
            }
        } finally {
            umask($previous);
            @unlink($temporary);
        }
    }
}
