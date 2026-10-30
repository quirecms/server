<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Doctrine\DBAL\Connection;
use Quire\Server\Site\SiteName;

/**
 * One operation at a time per site, across processes: a PostgreSQL advisory lock, released when the
 * operation ends or its connection does (so a crash never leaves a site locked). Operations on sites
 * also share the server's lock, which updating the server takes alone: it never stops the agent while
 * one runs, and none starts while it updates.
 *
 * Its locks are named in a namespace: the server's own, or a test's (its schema), so tests running
 * side by side against one PostgreSQL don't take each other's.
 */
final readonly class SiteLock
{
    public function __construct(
        private Connection $connection,
        private string $namespace = 'quire-server',
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
        $key = $this->namespace.':site:'.$site->value;

        if (true !== $this->connection->fetchOne('SELECT pg_try_advisory_lock_shared(hashtextextended(?, 0))', [$this->namespace.':server'])) {
            throw new \DomainException('The server is being updated right now; try again when it\'s finished ("quire-server operations").');
        }

        try {
            if (true !== $this->connection->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$key])) {
                throw new \DomainException(\sprintf('Something else is being done to the site "%s" right now; try again when it\'s finished.', $site));
            }

            try {
                return $operation();
            } finally {
                $this->connection->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
            }
        } finally {
            $this->connection->fetchOne('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$this->namespace.':server']);
        }
    }

    /**
     * The server's lock, alone: true when no operation on a site runs (and none can start until it's
     * released, or this connection ends).
     */
    public function lockServer(): bool
    {
        return true === $this->connection->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$this->namespace.':server']);
    }

    public function unlockServer(): void
    {
        $this->connection->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$this->namespace.':server']);
    }
}
