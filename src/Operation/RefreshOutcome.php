<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

/**
 * What bringing sites up to date at the agent's start did (SiteOverrides::refresh): the sites it
 * wrote, those it couldn't, and why, and those it couldn't yet because a lock was held (an update of
 * the server holds the server's while it starts the agent of the release it updates to). Those are
 * tried again.
 */
final readonly class RefreshOutcome
{
    /**
     * @param array<string, string> $failed Site => what failed: said, and tried again at the next start
     * @param list<string>          $busy   Sites refused because a lock was held: tried again soon
     * @param list<string>          $done   Sites whose compose.server.yaml it wrote (and, when it changed, started again with it)
     */
    public function __construct(
        public array $failed = [],
        public array $busy = [],
        public array $done = [],
    ) {
    }
}
