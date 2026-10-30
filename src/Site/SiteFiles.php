<?php

declare(strict_types=1);

namespace Quire\Server\Site;

use Quire\Server\Docker\PolicyContext;
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

    /** The server's PostgreSQL, on the quire-data network */
    public const string DATABASE_HOST = 'quire-database';

    /**
     * How the front door names a site's domain on quire-edge: "<domain>.quire.internal". The ".internal"
     * domain is never public (ICANN keeps it for private use): while the site is down, its name doesn't
     * resolve anywhere else, and visitors get the "Back in a moment" page. A site's public domain would
     * be asked of public DNS then, which names this server: the front door again, in a loop.
     */
    public const string EDGE_SUFFIX = '.quire.internal';

    /** The skeleton's production setup, then the server's */
    private const string COMPOSE_FILE = 'compose.yaml:compose.prod.yaml:compose.server.yaml';

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
     * @param list<Domain> $domains The primary one first
     * @param string|null  $project Its Compose project (its own for an adopted site), the site's name if null
     */
    public function writeOverride(string $directory, SiteName $site, array $domains, string $databaseMode, ?string $project = null): void
    {
        if ([] === $domains) {
            throw new \InvalidArgumentException('A site needs at least one domain.');
        }

        $hosts = array_map(static fn (Domain $domain): string => $domain->value, $domains);
        $primary = $hosts[0];
        $hardening = ['security_opt' => ['no-new-privileges:true'], 'cap_drop' => ['ALL']];
        $php = [
            'image' => self::image($project ?? $site->value),
            // Only the front door reaches the site.
            'ports' => new TaggedValue('reset', null),
            'environment' => [
                // Plain HTTP for its domains: the front door has the certificates.
                'SERVER_NAME' => implode(', ', array_map(static fn (string $host): string => 'http://'.$host, $hosts)).', php:80',
                'DEFAULT_URI' => 'https://'.$primary,
                'SYMFONY_TRUSTED_PROXIES' => 'private_ranges',
            ],
            'labels' => ['com.quirecms.site' => 'true', 'com.quirecms.server.site' => $site->value],
            'networks' => ['default' => null, 'quire-edge' => ['aliases' => array_map(static fn (string $host): string => $host.self::EDGE_SUFFIX, $hosts)]],
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

        $networks = ['quire-edge' => ['external' => true]];

        if (Site::SHARED_DATABASE === $databaseMode) {
            // The server's PostgreSQL, on its network; the site's own doesn't run.
            $services['php']['networks']['quire-data'] = null;
            $services['worker']['networks']['quire-data'] = null;
            $networks['quire-data'] = ['external' => true];
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

            if (!rename($temporary, $path)) {
                throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $path));
            }
        } finally {
            umask($previous);
            @unlink($temporary);
        }
    }
}
