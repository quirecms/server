<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

/**
 * Brings back the repository as it was at a time (D-37), from the versions a protected bucket keeps:
 * each object deleted or changed since is made current again from its version of then (a copy, so a
 * key that can't delete versions does it). Objects made since are left (backups made after it). It
 * needs the bucket's versions listed, which the server's own key may not be allowed to.
 *
 * @phpstan-import-type Version from S3
 */
final readonly class BackupRecovery
{
    public function __construct(
        private S3 $s3,
    ) {
    }

    /**
     * @param bool $dryRun Only say what it would do
     *
     * @return array{restored: list<string>, newer: int, failed: list<string>}
     */
    public function recover(\DateTimeImmutable $before, bool $dryRun = false): array
    {
        $prefix = '' === $this->s3->prefix ? '' : $this->s3->prefix.'/';
        $byKey = [];

        foreach ($this->s3->versions($prefix) as $entry) {
            if ($entry['key'] !== $this->s3->key('quire-probe')) {
                $byKey[$entry['key']][] = $entry;
            }
        }

        $outcome = ['restored' => [], 'newer' => 0, 'failed' => []];

        foreach ($byKey as $entries) {
            // The key from the entry: PHP makes a numeric array key an integer
            $key = $entries[0]['key'];
            usort($entries, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);
            $current = $entries[0];

            if ($current['modified'] <= $before) {
                continue;
            }

            $then = array_find($entries, static fn (array $entry): bool => $entry['modified'] <= $before);

            if (null === $then || $then['marker']) {
                // Made since (or deleted then too): not the repository as it was
                $outcome['newer'] += $current['marker'] ? 0 : 1;

                continue;
            }

            // Already as it was then (brought back before): its content's hash says so
            if (!$current['marker'] && '' !== $current['etag'] && $current['etag'] === $then['etag']) {
                continue;
            }

            if (!$dryRun) {
                $status = $this->s3->restoreVersion($key, $then['version']);

                if ($status < 200 || $status >= 300) {
                    $outcome['failed'][] = \sprintf('%s (HTTP %d)', $key, $status);

                    continue;
                }
            }

            $outcome['restored'][] = $key;
        }

        return $outcome;
    }
}
