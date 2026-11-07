<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

use Doctrine\DBAL\Connection;
use Quire\Server\Registry\Schema;

/**
 * The problems that need the owner (D-35), as the registry keeps them: from when each started, whether
 * the owner was told, and when it was over. A problem is told once when it starts, again each week
 * while it lasts, and once when it's over; then it's forgotten (the operations' record keeps the
 * history).
 *
 * @phpstan-type Stored array{problem: Problem, since: \DateTimeImmutable, notified_at: ?\DateTimeImmutable, resolved_at: ?\DateTimeImmutable, dismissed: bool}
 */
final readonly class Problems
{
    /** Problems about the notifications themselves: kept by the notifier, not by the watch */
    public const string NOTIFY = 'notify';

    /** A problem still there after this long is told again */
    public const string REMIND_AFTER = '7 days';

    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    /**
     * The problems the watch found in this round: new ones start, those still there are kept (a newer
     * failure of the same kind starts again, told again), and those it didn't find are over.
     *
     * @param list<Problem> $found
     */
    public function sync(array $found): void
    {
        $this->connection->transactional(function () use ($found): void {
            $keys = [];

            foreach ($found as $problem) {
                if (self::NOTIFY === $problem->key) {
                    throw new \LogicException('The notifier keeps its own problem.');
                }

                $keys[] = $problem->key;
                $this->keep($problem);
            }

            $this->connection->executeStatement(
                \sprintf('UPDATE %s.problem SET resolved_at = now() WHERE resolved_at IS NULL AND key <> ? AND NOT (key = ANY (?::text[]))', $this->schema()),
                [self::NOTIFY, '{'.implode(',', array_map(static fn (string $key): string => '"'.$key.'"', $keys)).'}'],
            );
        });
    }

    /**
     * One problem, started or kept, outside the watch's rounds (the notifier's own).
     */
    public function raise(Problem $problem): void
    {
        $this->keep($problem);
    }

    /** A problem is over (the notifier's own) */
    public function clear(string $key): void
    {
        $this->connection->executeStatement(\sprintf('UPDATE %s.problem SET resolved_at = now() WHERE key = ? AND resolved_at IS NULL', $this->schema()), [$key]);
    }

    /**
     * The owner knows, and doesn't want to hear of it again: it's no longer told or shown, until it's
     * over or a newer failure of the same kind starts it again.
     */
    public function dismiss(string $key): bool
    {
        return 1 === (int) $this->connection->executeStatement(\sprintf('UPDATE %s.problem SET dismissed = true WHERE key = ? AND resolved_at IS NULL', $this->schema()), [$key]);
    }

    /**
     * The problems there are now, oldest first.
     *
     * @return list<Stored>
     */
    public function open(bool $withDismissed = false): array
    {
        return $this->rows('resolved_at IS NULL'.($withDismissed ? '' : ' AND NOT dismissed'));
    }

    /**
     * Problems told only once they've lasted this long: one that's over sooner is forgotten untold
     * (memory: a build's peak passes by itself).
     */
    public const array DELAYED = ['memory' => '10 minutes'];

    /**
     * What to tell the owner now: problems that started and weren't told, those still there a week
     * after they last were, and those over since they were told.
     *
     * @return array{started: list<Stored>, reminded: list<Stored>, over: list<Stored>}
     */
    public function due(): array
    {
        return [
            'started' => $this->rows('resolved_at IS NULL AND NOT dismissed AND notified_at IS NULL'.$this->notYet()),
            'reminded' => $this->rows(\sprintf("resolved_at IS NULL AND NOT dismissed AND notified_at < now() - interval '%s'", self::REMIND_AFTER)),
            'over' => $this->rows('resolved_at IS NOT NULL AND notified_at IS NOT NULL'),
        ];
    }

    /**
     * The owner was told of these: started and reminded ones are marked, those that are over are
     * forgotten.
     *
     * @param list<string> $told
     * @param list<string> $over
     */
    public function told(array $told, array $over): void
    {
        $this->connection->transactional(function () use ($told, $over): void {
            foreach ($told as $key) {
                $this->connection->executeStatement(\sprintf('UPDATE %s.problem SET notified_at = now() WHERE key = ? AND resolved_at IS NULL', $this->schema()), [$key]);
            }

            foreach ($over as $key) {
                $this->connection->executeStatement(\sprintf('DELETE FROM %s.problem WHERE key = ? AND resolved_at IS NOT NULL', $this->schema()), [$key]);
            }
        });
    }

    /**
     * Problems that were over before anyone was told of them, or dismissed: nothing to tell.
     */
    public function forgetUntold(): void
    {
        $this->connection->executeStatement(\sprintf('DELETE FROM %s.problem WHERE resolved_at IS NOT NULL AND (notified_at IS NULL OR dismissed)', $this->schema()));
    }

    private function notYet(): string
    {
        $clauses = '';

        foreach (self::DELAYED as $key => $interval) {
            $clauses .= \sprintf(' AND NOT (key = %s AND since > now() - interval %s)', $this->connection->quote($key), $this->connection->quote($interval));
        }

        return $clauses;
    }

    private function keep(Problem $problem): void
    {
        // A problem that comes back before its end was told isn't over; a newer failure of the same kind
        // (another operation) starts again, to be told again.
        $this->connection->executeStatement(
            \sprintf(
                <<<'SQL'
                    INSERT INTO %1$s.problem AS p (key, site, summary, advice, operation_id) VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT (key) DO UPDATE SET
                        site = excluded.site, summary = excluded.summary, advice = excluded.advice, seen_at = now(), resolved_at = NULL,
                        operation_id = excluded.operation_id,
                        since = CASE WHEN p.operation_id IS DISTINCT FROM excluded.operation_id OR p.resolved_at IS NOT NULL AND p.notified_at IS NULL THEN now() ELSE p.since END,
                        notified_at = CASE WHEN p.operation_id IS DISTINCT FROM excluded.operation_id THEN NULL ELSE p.notified_at END,
                        dismissed = CASE WHEN p.operation_id IS DISTINCT FROM excluded.operation_id THEN false ELSE p.dismissed END
                    SQL,
                $this->schema(),
            ),
            [$problem->key, $problem->site, $problem->summary, $problem->advice, $problem->operation],
        );
    }

    /**
     * @return list<Stored>
     */
    private function rows(string $where): array
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf('SELECT key, site, summary, advice, operation_id, since, notified_at, resolved_at, dismissed FROM %s.problem WHERE %s ORDER BY since, key', $this->schema(), $where));

        return array_map(static fn (array $row): array => [
            'problem' => new Problem(self::text($row['key']), self::text($row['summary']), self::text($row['advice']), null === $row['site'] ? null : self::text($row['site']), null === $row['operation_id'] ? null : self::text($row['operation_id']), self::time($row['since'])),
            'since' => self::time($row['since']) ?? new \DateTimeImmutable(),
            'notified_at' => self::time($row['notified_at']),
            'resolved_at' => self::time($row['resolved_at']),
            'dismissed' => true === $row['dismissed'],
        ], $rows);
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
