<?php

declare(strict_types=1);

namespace Quire\Server\Update;

/**
 * Adding, updating or removing one package of a managed site, worked out and checked on a copy of its
 * composer files: an update plan (SiteUpdater applies it as it applies any), and what to show before
 * a person says yes. Every new package is shown with where its code comes from: the commit its
 * download names, which is what anchors it (G3; Packagist gives most GitHub downloads no checksum).
 */
final readonly class PackagePlan
{
    public const string ADD = 'add';
    public const string UPDATE = 'update';
    public const string REMOVE = 'remove';

    /**
     * @param list<array{package: string, version: string, type: string, quire: bool, source: ?string, download: ?string, reference: ?string, from: ?string}> $arrived The packages whose code is new: those it adds (from null) or moves, by name
     */
    public function __construct(
        public UpdatePlan $plan,
        public string $action,
        public string $package,
        public bool $thirdParty,
        public ?string $version,
        public array $arrived,
    ) {
    }

    public function discard(): void
    {
        $this->plan->discard();
    }
}
