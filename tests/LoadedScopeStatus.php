<?php

declare(strict_types=1);

namespace Quire\Server\Tests;

use Quire\Server\Docker\ScopeStatus;

/**
 * A gate that reads every scope file at once and has it in effect, unless a test says otherwise:
 * what it was asked is kept, to check.
 */
final class LoadedScopeStatus implements ScopeStatus
{
    /** @var list<array{string, ?string}> Each question: the project, and the SHA-256 (null: whether it's gone) */
    public array $asked = [];

    /**
     * @param array<string, array{state: string, reason: ?string}> $states What the gate makes of some projects' scopes
     */
    public function __construct(
        public array $states = [],
    ) {
    }

    public function read(string $project, string $sha256): array
    {
        $this->asked[] = [$project, $sha256];

        return $this->states[$project] ?? ['state' => 'loaded', 'reason' => null];
    }

    public function gone(string $project): void
    {
        $this->asked[] = [$project, null];
    }
}
