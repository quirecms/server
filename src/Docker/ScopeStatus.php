<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

/**
 * Whether the gate has a site's scope file in effect: what it says when asked, rather than a sleep
 * past its scan (YorvoGate 0.3's scope status).
 */
interface ScopeStatus
{
    /**
     * Waits until the gate has read the site's scope file with this SHA-256, then says what it made of it.
     *
     * @return array{state: string, reason: ?string} "loaded" when it's in effect; "refused", "reserved" or "unknown" otherwise
     *
     * @throws \RuntimeException When the gate can't be asked, or didn't read that file in time
     */
    public function read(string $project, string $sha256): array;

    /**
     * Waits until the gate has no scope file for the site any more.
     *
     * @throws \RuntimeException When the gate can't be asked, or still had one in time
     */
    public function gone(string $project): void;
}
