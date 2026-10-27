<?php

declare(strict_types=1);

namespace Quire\Server\Update;

/**
 * An update (or undoing one) failed: the site is as it was before it when $restored, otherwise it's
 * marked failed and needs a person (the operation's log says what happened, and where its backup is).
 */
final class UpdateFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $operationId,
        public readonly bool $restored,
        string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
