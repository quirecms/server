<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

/**
 * Whether the backups off the server survive someone who has server.env (D-37): proved by trying what
 * they would, with the same keys, on an object of its own (quire-probe, in the repository's folder),
 * not by reading a setting. Protected means: the bucket keeps every version (a delete only hides it),
 * these keys can't delete a version, even bypassing a lock in governance mode, nor shorten its
 * retention. Then restic works as ever, and what's deleted stays recoverable until the retention ends
 * ("quire-server backups:recover").
 */
final readonly class BackupProtection
{
    public const string CHECK = 'backups.protection';
    private const string PROBE = 'quire-probe';

    /** Under this, it's protected for too short a time to notice and recover */
    private const int DAYS_MIN = 7;

    /**
     * @param \Closure(): \DateTimeImmutable|null $now
     */
    public function __construct(
        private S3 $s3,
        private ?\Closure $now = null,
    ) {
    }

    /**
     * @return array{protected: bool, summary: string, advice: string}
     */
    public function probe(): array
    {
        $key = $this->s3->key(self::PROBE);
        $written = $this->s3->put($key, "Quire Server checks with this object, every night, that the bucket keeps what's deleted.\n");

        if ($written['status'] < 200 || $written['status'] >= 300) {
            return $this->unprotected(\sprintf('the bucket refused a test object (HTTP %d), so it isn\'t known', $written['status']));
        }

        if (null === $written['version'] || '' === $written['version'] || 'null' === $written['version']) {
            return $this->unprotected('the bucket doesn\'t keep versions: whoever has these keys (server.env) can delete every backup for good');
        }

        if ($this->deleted($this->s3->deleteVersion($key, $written['version'], bypassGovernance: true))) {
            return $this->unprotected('these keys (server.env) can delete a backup\'s version for good: the bucket has no lock, or one they may bypass');
        }

        $retention = $this->s3->retention($key, $written['version']);

        if (null === $retention) {
            return $this->unprotected('what\'s written has no retention: the bucket has no default lock (Object Lock with a default retention)');
        }

        if ($this->deleted($this->s3->retain($key, $written['version'], $retention['mode'], $this->now()->modify('+1 minute')))) {
            return $this->unprotected('these keys (server.env) can shorten a backup\'s retention, then delete it');
        }

        $days = intdiv($retention['until']->getTimestamp() - $this->now()->getTimestamp() + 3600, 86400);

        if ($days < self::DAYS_MIN) {
            return ['protected' => false, 'summary' => \sprintf('Backups off the server are protected for %d day(s) only: a deletion not noticed and undone in that time is for good.', $days), 'advice' => \sprintf('Set the bucket\'s default retention (Object Lock) to %d days or more; 30 is usual.', self::DAYS_MIN)];
        }

        return ['protected' => true, 'summary' => \sprintf('Backups off the server are protected: what\'s deleted stays recoverable for %d days (%s retention).', $days, strtolower($retention['mode'])), 'advice' => ''];
    }

    private function deleted(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * @return array{protected: false, summary: string, advice: string}
     */
    private function unprotected(string $why): array
    {
        return [
            'protected' => false,
            'summary' => 'Backups off the server aren\'t protected: '.$why.'.',
            'advice' => 'Use a bucket made with Object Lock (compliance mode, a default retention of 30 days) and versioning, and a key that can\'t delete versions or bypass retention: see "Backups" in the README for each provider. Then "quire-server backups:setup" again.',
        ];
    }

    private function now(): \DateTimeImmutable
    {
        return $this->now instanceof \Closure ? ($this->now)() : new \DateTimeImmutable();
    }
}
