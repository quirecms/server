<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Quire\Server\Operation\OperationLog;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The backups the server makes of each site on itself before anything risky (an update, a deploy, a
 * restore…), in /srv/quire/backups/<site>/: kept for a while, so they can be undone, then removed, or
 * a site that's deployed often fills the disk. A site's newest backup is always kept, and so is the
 * final one of a site that was removed. Off-server backups have rules of their own (restic's).
 */
final readonly class LocalBackups
{
    /** How long a backup is kept: longer than an update can be undone (a week) */
    public const int KEEP_DAYS = 14;

    /** A backup, a dump beside it, or the files an update or undo kept: "<kind>-<time>…" */
    private const string ENTRY = '/^([a-z-]+)-(\d{8}-\d{6})(\.tar|\.database\.dump)?\z/';

    private const string ACTION = 'backups.expire';

    public function __construct(
        private OperationLog $operations,
        private string $root,
        private Filesystem $filesystem = new Filesystem(),
        /** @var (\Closure(): \DateTimeImmutable)|null */
        private ?\Closure $now = null,
    ) {
    }

    /**
     * Whether a day has passed since they were last looked at.
     */
    public function due(): bool
    {
        $last = $this->operations->lastStarted(self::ACTION);

        return !$last instanceof \DateTimeImmutable || $last < $this->now()->modify('-1 day');
    }

    /**
     * @return list<string> What was removed
     */
    public function expire(string $channel, string $requestedBy): array
    {
        $operation = $this->operations->start(self::ACTION, null, ['keep_days' => self::KEEP_DAYS], $channel, $requestedBy);
        $before = $this->now()->modify(\sprintf('-%d days', self::KEEP_DAYS));
        $removed = [];

        foreach ($this->entries($this->root) as $site) {
            $folder = $this->root.'/'.$site;

            if (str_starts_with($site, '.') || !is_dir($folder) || \in_array($site, ['server', 'database'], true)) {
                continue;
            }

            $entries = [];

            foreach ($this->entries($folder) as $entry) {
                if (1 === preg_match(self::ENTRY, $entry, $match) && 'final' !== $match[1]) {
                    $at = \DateTimeImmutable::createFromFormat('Ymd-His', $match[2]);
                    $entries[$entry] = false === $at ? null : $at;
                }
            }

            $newest = null;

            foreach ($entries as $entry => $at) {
                if (str_ends_with($entry, '.tar') && $at instanceof \DateTimeImmutable && (null === $newest || $at > $entries[$newest])) {
                    $newest = $entry;
                }
            }

            foreach ($entries as $entry => $at) {
                // The newest backup stays, with its dump.
                if (null !== $newest && (substr($newest, 0, -4) === $entry || $newest === $entry || SiteDatabaseDumps::of($newest) === $entry)) {
                    continue;
                }

                if ($at instanceof \DateTimeImmutable && $at < $before) {
                    $this->filesystem->remove($folder.'/'.$entry);
                    $removed[] = $site.'/'.$entry;
                    $operation->line('Removed '.$site.'/'.$entry);
                }
            }
        }

        $operation->succeed();

        return $removed;
    }

    /**
     * @return list<string>
     */
    private function entries(string $folder): array
    {
        $entries = @scandir($folder);

        return false === $entries ? [] : array_values(array_diff($entries, ['.', '..']));
    }

    private function now(): \DateTimeImmutable
    {
        return $this->now instanceof \Closure ? ($this->now)() : new \DateTimeImmutable();
    }
}
