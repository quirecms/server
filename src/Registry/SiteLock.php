<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Doctrine\DBAL\Connection;
use Quire\Server\Site\SiteName;

/**
 * One operation at a time per site, across processes: a PostgreSQL advisory lock, released when the
 * operation ends or its connection does (so a crash never leaves a site locked).
 */
final readonly class SiteLock
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function run(SiteName $site, \Closure $operation): mixed
    {
        $key = 'quire-server:site:'.$site->value;

        if (true !== $this->connection->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$key])) {
            throw new \DomainException(\sprintf('Something else is being done to the site "%s" right now; try again when it\'s finished.', $site));
        }

        try {
            return $operation();
        } finally {
            $this->connection->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
        }
    }
}
