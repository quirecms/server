<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Quire\Server\Operation\OperationLog;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The backups the server makes of each site on itself before anything risky (an update, a deploy, a
 * restore…), in /srv/quire/backups/<site>/: kept for a while, so they can be undone, then removed, or
 * a site that's deployed often fills the disk. A site's newest backup is always kept, and its newest
 * complete one (when the newest lacks some stored files: SiteBackup::marker()), and so is the final one
 * of a site that was removed. Off-server backups have rules of their own (restic's).
 */
final readonly class LocalBackups
{
    /** How long a backup is kept: longer than an update can be undone (a week) */
    public const int KEEP_DAYS = 14;

    /** A backup, a dump or a mark of incomplete beside it, or the files an update or undo kept: "<kind>-<time>…" */
    private const string ENTRY = '/^([a-z-]+)-(\d{8}-\d{6})(\.tar|\.database\.dump|\.incomplete)?\z/';

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

            $newest = $newestComplete = null;

            foreach ($entries as $entry => $at) {
                if (!str_ends_with($entry, '.tar') || !$at instanceof \DateTimeImmutable) {
                    continue;
                }

                if (null === $newest || $at > $entries[$newest]) {
                    $newest = $entry;
                }

                if (!isset($entries[basename(SiteBackup::marker($entry))]) && (null === $newestComplete || $at > $entries[$newestComplete])) {
                    $newestComplete = $entry;
                }
            }

            $kept = [];

            // The newest backup stays, and the newest complete one: each with what lies beside it.
            foreach (array_filter([$newest, $newestComplete]) as $backup) {
                $kept[] = $backup;
                $kept[] = substr($backup, 0, -4);
                $kept[] = SiteDatabaseDumps::of($backup);
                $kept[] = basename(SiteBackup::marker($backup));
            }

            foreach ($entries as $entry => $at) {
                if (\in_array($entry, $kept, true)) {
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
     * A site's backups on the server, newest first: what can bring it back as it was before an
     * operation ("final": before it was removed; "before-restore", "before-undo", "before-update"…).
     *
     * @return list<array{file: string, kind: string, made: \DateTimeImmutable, size: int, complete: bool}>
     */
    public function of(\Quire\Server\Site\SiteName $site): array
    {
        $folder = $this->root.'/'.$site->value;
        $backups = [];

        foreach ($this->entries($folder) as $entry) {
            if (1 === preg_match(self::ENTRY, $entry, $match) && '.tar' === ($match[3] ?? '') && !is_link($folder.'/'.$entry) && is_file($folder.'/'.$entry)) {
                $made = \DateTimeImmutable::createFromFormat('Ymd-His', $match[2], new \DateTimeZone('UTC'));

                if (false !== $made) {
                    $backups[] = ['file' => $entry, 'kind' => $match[1], 'made' => $made, 'size' => (int) filesize($folder.'/'.$entry), 'complete' => !is_file(SiteBackup::marker($folder.'/'.$entry))];
                }
            }
        }

        usort($backups, static fn (array $a, array $b): int => $b['made'] <=> $a['made']);

        return $backups;
    }

    /**
     * One of a site's backups on the server, by its name ("final-20261104-120000.tar"): only a plain
     * file in its own folder (never another's, a path elsewhere or a link).
     *
     * @throws \DomainException When it isn't one
     */
    public function find(\Quire\Server\Site\SiteName $site, string $file): string
    {
        $name = basename($file);

        if ($name !== $file && $this->root.'/'.$site->value.'/'.$name !== $file) {
            throw new \DomainException(\sprintf('"%s" isn\'t one of "%s"\'s backups: give its name, as "quire-server backups %2$s --local" lists them.', $file, $site));
        }

        foreach ($this->of($site) as $backup) {
            if ($backup['file'] === $name) {
                return $this->root.'/'.$site->value.'/'.$name;
            }
        }

        throw new \DomainException(\sprintf('"%s" has no backup "%s" on the server ("quire-server backups %1$s --local" lists them).', $site, $name));
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
