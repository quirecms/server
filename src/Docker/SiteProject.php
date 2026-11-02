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

    /**
     * A project the server acts for on the Docker gate: a site's name's rules, as adopted sites' projects
     * follow since 0.3.2. Never one of the server's own names, which all start with "quire" (on the
     * gate, a project owns whatever carries its label), and only names Docker's DNS takes as aliases
     * ("<project>-php-1"): an older adopted project with "_" has to be adopted again under a new one.
     *
     * @throws \InvalidArgumentException When it isn't a project the server acts for
     */
    public static function checked(string $project): string
    {
        try {
            return new SiteName($project)->value;
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(\sprintf('The Compose project "%s" can\'t be given a scope on the Docker gate (%s). Adopt the site again with COMPOSE_PROJECT_NAME set to its name.', SiteName::printable($project), $e->getMessage()), $e->getCode(), previous: $e);
        }
    }
}
