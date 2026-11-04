<?php

declare(strict_types=1);

namespace Quire\Server\Process;

/**
 * A program the server ran took longer than its time limit, and was stopped (M-20): said as such, not
 * as its whole command line.
 */
final class ProcessTimedOut extends \RuntimeException
{
    /**
     * @param list<string> $command
     */
    public function __construct(public readonly array $command, public readonly float $timeout, ?\Throwable $previous = null)
    {
        $limit = $timeout >= 120 ? \sprintf('%d minutes', (int) round($timeout / 60)) : \sprintf('%d seconds', (int) $timeout);
        $what = match (true) {
            'docker' === ($command[0] ?? null) && \in_array('build', $command, true) => 'Building the site',
            'docker' === ($command[0] ?? null) => 'Docker ('.implode(' ', \array_slice(array_values(array_filter($command, static fn (string $word): bool => !str_starts_with($word, '-') && !str_contains($word, '/'))), 1, 2)).')',
            default => ucfirst(basename($command[0] ?? 'A program')),
        };

        parent::__construct(\sprintf('%s took longer than %s, its time limit, and was stopped: the machine may be short of memory or disk, or a host it needs slow to answer. "quire-server operations <id>" shows the last thing it did.', $what, $limit), 0, $previous);
    }
}
