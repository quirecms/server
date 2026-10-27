<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

use Quire\Server\Git\Repository;
use Quire\Server\Site\Domain;
use Quire\Server\Site\SiteName;

/**
 * What adding a site needs, validated: its name, domains, title and first admin.
 */
final readonly class SiteRequest
{
    /**
     * @param list<Domain> $domains The primary one first
     */
    public function __construct(
        public SiteName $name,
        public array $domains,
        public string $title,
        public string $adminEmail,
        public bool $invite,
        public string $quireVersion,
        /** Git mode: the site is this repository's branch, instead of the skeleton */
        public ?Repository $repository = null,
        /** Git mode: the agent deploys new commits of the branch by itself */
        public bool $poll = false,
    ) {
        if ([] === $domains) {
            throw new \InvalidArgumentException('A site needs at least one domain.');
        }

        if (\count(array_unique(array_map(static fn (Domain $domain): string => $domain->value, $domains))) !== \count($domains)) {
            throw new \InvalidArgumentException('A domain is given twice.');
        }

        if ('' === trim($title) || mb_strlen($title) > 120 || 1 === preg_match('/[\x00-\x1f\x7f]/', $title)) {
            throw new \InvalidArgumentException('The title must be 1 to 120 characters, without control characters.');
        }

        if (false === filter_var($adminEmail, \FILTER_VALIDATE_EMAIL) || str_starts_with($adminEmail, '-')) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t an email address.', SiteName::printable($adminEmail)));
        }
    }
}
