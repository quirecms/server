<?php

declare(strict_types=1);

namespace Quire\Server\ServerUpdate;

/**
 * Updating the server (or undoing it) failed. $restored says whether the server runs as it was
 * before; when it doesn't, it needs a person, and its backup is in $backup.
 */
final class ServerUpdateFailed extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $restored,
        public readonly ?string $backup = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
