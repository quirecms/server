<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Site\SiteName;

/**
 * What updating a site would change, worked out before anything changes: its packages, the skeleton's
 * files, the known vulnerabilities before and after. It holds its own folder (the new composer files,
 * the new skeleton), until it's applied or discarded.
 */
final readonly class UpdatePlan
{
    /**
     * @param list<array{package: string, from: ?string, to: ?string}>                                  $packages
     * @param list<array{path: string, action: string}>                                                 $skeletonFiles
     * @param array<string, list<array{title: string, cve: ?string, link: ?string, severity: ?string}>> $advisoriesBefore
     * @param array<string, list<array{title: string, cve: ?string, link: ?string, severity: ?string}>> $advisoriesAfter
     * @param string                                                                                    $siteFiles        A hash of the site's composer files when planned: it must not change before it's applied
     */
    public function __construct(
        public SiteName $site,
        public string $workspace,
        public Lock $from,
        public Lock $to,
        public string $composerJson,
        public array $packages,
        public array $skeletonFiles,
        public ?string $skeletonTarget,
        public bool $skeletonBaseKnown,
        public array $advisoriesBefore,
        public array $advisoriesAfter,
        public string $siteFiles,
    ) {
    }

    public function changesAnything(): bool
    {
        return [] !== $this->packages || [] !== $this->appliedSkeletonFiles();
    }

    /**
     * @return list<array{path: string, action: string}>
     */
    public function conflicts(): array
    {
        return array_values(array_filter($this->skeletonFiles, static fn (array $change): bool => SkeletonFiles::CONFLICT === $change['action']));
    }

    /**
     * @return list<array{path: string, action: string}>
     */
    public function appliedSkeletonFiles(): array
    {
        return array_values(array_filter($this->skeletonFiles, static fn (array $change): bool => SkeletonFiles::CONFLICT !== $change['action']));
    }

    public function quireFrom(): ?string
    {
        return $this->from->quire();
    }

    public function quireTo(): ?string
    {
        return $this->to->quire();
    }

    /**
     * @return array<string, list<array{title: string, cve: ?string, link: ?string, severity: ?string}>> Advisories the update fixes
     */
    public function fixedAdvisories(): array
    {
        return array_diff_key($this->advisoriesBefore, $this->advisoriesAfter);
    }

    public function discard(): void
    {
        new \Symfony\Component\Filesystem\Filesystem()->remove($this->workspace);
    }
}
