<?php

declare(strict_types=1);

namespace Quire\Server\Git;

use Quire\Server\Registry\SiteGit;
use Quire\Server\Update\SiteUpdater;

/**
 * The agent's watch on Git mode sites whose branch it deploys by itself: every few minutes, the
 * repository is asked for its branch's newest commit (without fetching anything); a new one is
 * deployed, unless it failed before.
 */
final readonly class GitPoller
{
    public const string REQUESTER = 'polling';

    /** No repository is asked more often than this */
    private const int MINIMUM = 60;

    public function __construct(
        private SiteGit $repositories,
        private Git $git,
        private SiteUpdater $updater,
        private int $seconds = 300,
    ) {
    }

    /**
     * @param \Closure(string): void $say
     */
    public function poll(\Closure $say): void
    {
        foreach ($this->repositories->dueForPolling(max(self::MINIMUM, $this->seconds)) as $site) {
            $this->repositories->polled($site);
            $git = $this->repositories->of($site);

            if (null === $git) {
                continue;
            }

            try {
                $head = $this->git->remoteHead($git['repository'], $site);

                if ($head === $git['deployed'] || $head === $git['failed']) {
                    continue;
                }

                $say(\sprintf('%s: deploying commit %s', $site, substr($head, 0, 12)));
                $this->updater->deploy($site, $head, 'agent', self::REQUESTER, static fn (string $step) => $say(\sprintf('%s: %s', $site, $step)));
                $say(\sprintf('%s: deployed %s', $site, substr($head, 0, 12)));
            } catch (\Throwable $e) {
                // Its operation's log has the details; the next round goes on with the other sites.
                $say(\sprintf('%s: %s', $site, $e->getMessage()));
            }
        }
    }
}
