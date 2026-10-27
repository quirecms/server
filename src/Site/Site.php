<?php

declare(strict_types=1);

namespace Quire\Server\Site;

/**
 * A site as the server's registry knows it.
 */
final readonly class Site
{
    public const string CREATING = 'creating';
    public const string RUNNING = 'running';
    public const string FAILED = 'failed';
    /** Being removed: not served any more, its data not gone yet */
    public const string REMOVING = 'removing';
    public const string REMOVED = 'removed';

    /** Its database is a database of the server's PostgreSQL */
    public const string SHARED_DATABASE = 'shared';
    /** It runs its own PostgreSQL container (e.g. a site adopted as it was) */
    public const string OWN_DATABASE = 'own';

    /**
     * @param list<Domain> $domains The primary one first
     */
    public function __construct(
        public SiteName $name,
        public string $status,
        public string $databaseMode,
        public string $project,
        public array $domains,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function primaryDomain(): ?Domain
    {
        return $this->domains[0] ?? null;
    }
}
