<?php

declare(strict_types=1);

namespace Quire\Server\ServerDatabase;

/**
 * Upgrading the server's PostgreSQL (or undoing it) failed. $restored says whether the server runs as
 * it did before, with its sites; when it doesn't, it needs a person.
 */
final class DatabaseUpgradeFailed extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $restored,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
