<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;
use Quire\Server\Site\SiteName;

/**
 * A site's Compose project on the server: its folder, the env file that selects its Compose files
 * and holds its settings (prod.env), and what the policy allows it.
 */
final readonly class SiteProject
{
    public function __construct(
        public string $directory,
        public string $envFile,
        public PolicyContext $policy,
    ) {
    }

    /**
     * How a site of this server runs: its folder, its prod.env, and what the policy allows it (its
     * project, and its names on the shared networks).
     *
     * @param list<Domain> $domains
     */
    public static function of(ServerPaths $paths, SiteName $name, array $domains, string $project, string $databaseMode): self
    {
        $directory = $paths->site($name);

        return new self($directory, $directory.'/'.SiteFiles::ENV_FILE, new PolicyContext($project, $directory, [
            'quire-edge' => ['php' => array_map(static fn (Domain $domain): string => $domain->value.SiteFiles::EDGE_SUFFIX, $domains)],
            'quire-data' => Site::SHARED_DATABASE === $databaseMode ? ['php' => [], 'worker' => []] : [],
        ]));
    }

    public function name(): string
    {
        return $this->policy->project;
    }
}
