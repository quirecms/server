<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

final class PolicyViolation extends \RuntimeException
{
    /**
     * @param list<string> $violations
     */
    public function __construct(string $project, public readonly array $violations)
    {
        parent::__construct(\sprintf("The Compose configuration of \"%s\" isn't allowed on this server:\n- %s", $project, implode("\n- ", $violations)));
    }
}
