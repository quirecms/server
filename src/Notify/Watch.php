<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

use Doctrine\DBAL\Connection;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Host\Memory;
use Quire\Server\Registry\Schema;
use Quire\Server\Site\ServerPaths;

/**
 * What needs the owner now (D-35), found from what the server already records and keeps, rather than
 * by each job: every job records its operation, so a failure the owner didn't see (the agent's own,
 * at night) is the latest operation of its kind having failed; a later one that succeeds, whoever ran
 * it, ends it. Also: nightly backups that stopped running, certificates that don't come or aren't
 * renewed, a disk filling up, memory running out, and what the nightly checks of the backups found
 * (Checks).
 */
final readonly class Watch
{
    /**
     * What the agent does by itself, what its failure means and what to do ({site}, {operation}: the
     * site's name and the operation's ID).
     */
    private const array OPERATIONS = [
        'site.update' => ['The automatic update of {site} failed.', 'A failed update is undone by itself, and the site runs as before: "quire-server operations {operation}" says whether it was, and why it failed. "quire-server site:update {site}" tries again.'],
        'site.rebuild' => ['{site} couldn\'t be built again on the newer build of Quire\'s runtime.', 'It runs on the build it had; why: "quire-server operations {operation}". It\'s tried again tonight.'],
        'site.deploy' => ['The automatic deploy of {site}\'s new commit failed.', 'A failed deploy is undone by itself, and the site runs the commit it ran before: "quire-server operations {operation}" says whether it was, and why it failed. "quire-server site:deploy {site}" tries again.'],
        'site.recover' => ['{site} couldn\'t be put back as it was after a release the agent\'s stop cut short.', 'Why: "quire-server operations {operation}". The site may be down: "quire-server site:list" says.'],
        'site.remove' => ['{site}, whose adding was cut short, couldn\'t be removed.', 'Why: "quire-server operations {operation}". "quire-server site:remove {site}" tries again.'],
        OffsiteBackups::NIGHTLY => ['Last night\'s backups off the server failed.', 'Not everything was backed up; why: "quire-server operations {operation}". "quire-server backups:now" tries again.'],
        'backups.expire' => ['Old backups on the server couldn\'t be removed.', 'The disk may fill up; why: "quire-server operations {operation}".'],
    ];

    /** Only failures this recent: older ones aren't news (a release that brings the watch included) */
    private const string RECENT = '14 days';

    /** Nightly backups that haven't run for this long have stopped */
    private const int BACKUPS_LATE = 36 * 3600;

    /** A domain gets its certificate within minutes; after this long, it doesn't */
    private const int CERTIFICATE_LATE = 3600;

    /** Free space under which the disk is filling up: a share of it, or a size */
    private const float DISK_SHARE = 0.10;
    private const int DISK_BYTES = 5 * 1024 ** 3;

    /** Available memory under which it's running out: a share of it, or a size, whichever is more */
    private const float MEMORY_SHARE = 0.10;
    private const int MEMORY_BYTES = 512 * 1024 ** 2;

    /**
     * @param \Closure(): \DateTimeImmutable                          $now
     * @param \Closure(string): array{free: float, total: float}|null $disk
     */
    public function __construct(
        private Connection $connection,
        private BackupSettings $backups,
        private Certificates $certificates,
        private ServerPaths $paths,
        private Checks $checks,
        private string $schema = Schema::NAME,
        private ?\Closure $now = null,
        private ?\Closure $disk = null,
        private ?Memory $memory = null,
    ) {
    }

    /**
     * @return list<Problem>
     */
    public function problems(): array
    {
        return [...$this->failures(), ...$this->lateBackups(), ...$this->certificatesProblems(), ...$this->diskProblems(), ...$this->memoryProblems(), ...($this->backups->configured() ? $this->checks->problems() : [])];
    }

    /**
     * The agent's own operations whose latest of its kind (per site) failed. A site that's gone takes
     * its problems with it.
     *
     * @return list<Problem>
     */
    private function failures(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT DISTINCT ON (o.action, coalesce(o.site, '')) o.id, o.action, o.site, o.channel, o.status
                    FROM %1$s.operation o
                    LEFT JOIN %1$s.site s ON s.name = o.site
                    WHERE o.status <> 'running' AND o.action = ANY (?::text[]) AND o.started_at > now() - interval '%2$s'
                        AND (o.site IS NULL OR s.status IN ('running', 'failed', 'creating'))
                    ORDER BY o.action, coalesce(o.site, ''), o.started_at DESC
                    SQL,
                $this->schema(),
                self::RECENT,
            ),
            ['{'.implode(',', array_keys(self::OPERATIONS)).'}'],
        );
        $problems = [];

        foreach ($rows as $row) {
            if ('failed' !== $row['status'] || 'agent' !== $row['channel'] || !\is_string($row['action']) || !isset(self::OPERATIONS[$row['action']])) {
                continue;
            }

            $site = \is_string($row['site']) ? $row['site'] : null;
            $id = \is_string($row['id']) ? $row['id'] : '';
            [$summary, $advice] = self::OPERATIONS[$row['action']];
            $problems[] = new Problem(
                'operation:'.$row['action'].(null === $site ? '' : ':'.$site),
                strtr($summary, ['{site}' => $site ?? '', '{operation}' => $id]),
                strtr($advice, ['{site}' => $site ?? '', '{operation}' => $id]),
                $site,
                $id,
            );
        }

        return $problems;
    }

    /**
     * @return list<Problem>
     */
    private function lateBackups(): array
    {
        if (!$this->backups->configured()) {
            return [];
        }

        // The night's, or the same asked for by hand
        $last = $this->connection->fetchOne(\sprintf('SELECT max(started_at) FROM %s.operation WHERE action IN (?, ?)', $this->schema()), [OffsiteBackups::NIGHTLY, OffsiteBackups::ALL]);

        if (!\is_string($last) || $this->now()->getTimestamp() - new \DateTimeImmutable($last)->getTimestamp() < self::BACKUPS_LATE) {
            return [];
        }

        return [new Problem('backups.late', 'Backups off the server haven\'t run since '.$this->when(new \DateTimeImmutable($last)).'.', 'They run every night while the agent runs: "quire-server status" says whether it does, "quire-server logs agent" what it said. "quire-server backups:now" backs up now.')];
    }

    /**
     * Domains of running sites without a certificate an hour after they were added, or whose
     * certificate isn't being renewed.
     *
     * @return list<Problem>
     */
    private function certificatesProblems(): array
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf("SELECT d.domain, d.site, d.created_at FROM %1\$s.domain d JOIN %1\$s.site s ON s.name = d.site WHERE s.status = 'running' ORDER BY d.domain", $this->schema()));
        $now = $this->now();
        $problems = [];

        foreach ($rows as $row) {
            if (!\is_string($row['domain']) || !\is_string($row['site']) || !\is_string($row['created_at'])) {
                continue;
            }

            $certificate = $this->certificates->of($row['domain']);

            if (null === $certificate) {
                if ($now->getTimestamp() - new \DateTimeImmutable($row['created_at'])->getTimestamp() >= self::CERTIFICATE_LATE) {
                    $problems[] = new Problem('certificate:'.$row['domain'], \sprintf('%s (site %s) has no certificate: visitors can\'t reach it over HTTPS.', $row['domain'], $row['site']), 'Its DNS must point at this server, and ports 80 and 443 must be open to the internet: the certificate authority checks both. "quire-server logs front" says what it answered. It\'s tried again by itself.', $row['site']);
                }
            } elseif (Certificates::overdue($certificate, $now)) {
                $expired = $certificate['not_after'] <= $now;
                $problems[] = new Problem('certificate:'.$row['domain'], \sprintf('%s (site %s): its certificate %s %s and isn\'t being renewed.', $row['domain'], $row['site'], $expired ? 'expired' : 'expires', $this->when($certificate['not_after'])), 'Its DNS must still point at this server, and ports 80 and 443 be open to the internet. "quire-server logs front" says what the certificate authority answered.', $row['site']);
            }
        }

        return $problems;
    }

    /**
     * @return list<Problem>
     */
    private function diskProblems(): array
    {
        $space = $this->disk instanceof \Closure ? ($this->disk)($this->paths->root) : ['free' => (float) @disk_free_space($this->paths->root), 'total' => (float) @disk_total_space($this->paths->root)];

        if ($space['total'] <= 0 || ($space['free'] >= $space['total'] * self::DISK_SHARE && $space['free'] >= self::DISK_BYTES)) {
            return [];
        }

        return [new Problem('disk', \sprintf('The disk is almost full: %s free of %s.', $this->size($space['free']), $this->size($space['total'])), 'Updates, builds and backups need room, and fail without it. Old local backups are in '.$this->paths->root.'/backups; "docker system df" says what Docker\'s images and build caches take.')];
    }

    /**
     * Told only once it has lasted 10 minutes (Problems::DELAYED): a build's peak passes by itself.
     *
     * @return list<Problem>
     */
    private function memoryProblems(): array
    {
        $available = $this->memory?->available();
        $total = $this->memory?->total();

        if (null === $available || null === $total || $available >= max($total * self::MEMORY_SHARE, self::MEMORY_BYTES)) {
            return [];
        }

        return [new Problem('memory', \sprintf('Memory is running out: %s free of %s.', Memory::size($available), Memory::size($total)), 'When it runs out, the kernel stops a program to free some: a site\'s container, which starts again by itself (the server\'s own come last). "docker stats" says what takes it. Add memory, or remove a site; new sites aren\'t built while too little is free.')];
    }

    private function now(): \DateTimeImmutable
    {
        return $this->now instanceof \Closure ? ($this->now)() : new \DateTimeImmutable();
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }

    private function when(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';
    }

    private function size(float $bytes): string
    {
        return $bytes >= 1024 ** 3 ? \sprintf('%.1f GB', $bytes / 1024 ** 3) : \sprintf('%d MB', (int) ($bytes / 1024 ** 2));
    }
}
