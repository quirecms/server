<?php

declare(strict_types=1);

namespace Quire\Server\FrontDoor;

use Quire\Server\Site\Domain;
use Quire\Server\Site\SiteName;

/**
 * What the front door may serve: a file per domain, named after it and holding the site's name, in a
 * folder the front door reads (and only the agent writes). The front door gets certificates only for
 * these domains, and passes on only requests for them; it needs no reload when they change.
 */
final readonly class DomainDirectory
{
    public function __construct(
        private string $directory,
    ) {
    }

    public function allow(Domain $domain, SiteName $site): void
    {
        $this->ensureDirectory();
        $path = $this->path($domain);
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (false === file_put_contents($temporary, $site->value."\n", \LOCK_EX)) {
            throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $temporary));
        }

        chmod($temporary, 0o644);

        if (!rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $path));
        }
    }

    public function revoke(Domain $domain): void
    {
        $path = $this->path($domain);

        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException(\sprintf('Couldn\'t remove "%s".', $path));
        }
    }

    /**
     * @return array<string, string> Domain => site name, of the files there
     */
    public function all(): array
    {
        $domains = [];

        $files = is_dir($this->directory) ? scandir($this->directory) : [];

        foreach (false === $files ? [] : $files as $file) {
            if (str_starts_with($file, '.') || str_ends_with($file, '.tmp') || !is_file($this->directory.'/'.$file)) {
                continue;
            }

            $domains[$file] = trim((string) file_get_contents($this->directory.'/'.$file));
        }

        ksort($domains);

        return $domains;
    }

    /**
     * Makes the folder match what the registry says: files for the domains of the sites that run, none
     * for others (e.g. after a crash between two steps).
     *
     * @param array<string, string> $expected Domain => site name
     *
     * @return array{added: list<string>, removed: list<string>}
     */
    public function reconcile(array $expected): array
    {
        $actual = $this->all();
        $added = [];
        $removed = [];

        foreach ($expected as $domain => $site) {
            if (($actual[$domain] ?? null) !== $site) {
                $this->allow(new Domain($domain), new SiteName($site));
                $added[] = $domain;
            }
        }

        foreach (array_keys($actual) as $domain) {
            if (!isset($expected[$domain])) {
                @unlink($this->directory.'/'.$domain);
                $removed[] = $domain;
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    private function path(Domain $domain): string
    {
        // A Domain is letters, digits, dots and dashes, never "." or "..": always a file in the folder.
        return $this->directory.'/'.$domain->value;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('Couldn\'t create "%s".', $this->directory));
        }
    }
}
