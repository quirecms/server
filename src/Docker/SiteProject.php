<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

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

    public function name(): string
    {
        return $this->policy->project;
    }
}
