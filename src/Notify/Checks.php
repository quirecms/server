<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

use Doctrine\DBAL\Connection;
use Quire\Server\Registry\Schema;

/**
 * What the nightly checks found (D-37), kept until they run again: the watch makes a problem of each
 * that fails ("check:<name>"), and "quire-server status" shows them all.
 */
final readonly class Checks
{
    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    public function record(string $name, bool $failing, string $summary, string $advice = ''): void
    {
        $this->connection->executeStatement(
            \sprintf('INSERT INTO %s.check_result (name, failing, summary, advice) VALUES (?, ?, ?, ?) ON CONFLICT (name) DO UPDATE SET failing = excluded.failing, summary = excluded.summary, advice = excluded.advice, checked_at = now()', $this->schema()),
            [$name, $failing, $summary, $advice],
            [1 => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );
    }

    /**
     * @return array{failing: bool, summary: string, advice: string, checked_at: \DateTimeImmutable}|null
     */
    public function get(string $name): ?array
    {
        $row = $this->connection->fetchAssociative(\sprintf('SELECT failing, summary, advice, checked_at FROM %s.check_result WHERE name = ?', $this->schema()), [$name]);

        return false === $row ? null : ['failing' => true === $row['failing'], 'summary' => \is_string($row['summary']) ? $row['summary'] : '', 'advice' => \is_string($row['advice']) ? $row['advice'] : '', 'checked_at' => new \DateTimeImmutable(\is_string($row['checked_at']) ? $row['checked_at'] : 'now')];
    }

    /**
     * @return list<Problem> Those that fail, as problems
     */
    public function problems(): array
    {
        return array_map(
            static fn (array $row): Problem => new Problem('check:'.(\is_string($row['name']) ? $row['name'] : ''), \is_string($row['summary']) ? $row['summary'] : '', \is_string($row['advice']) ? $row['advice'] : ''),
            $this->connection->fetchAllAssociative(\sprintf('SELECT name, summary, advice FROM %s.check_result WHERE failing ORDER BY name', $this->schema())),
        );
    }

    /** A check that no longer applies (backups no longer set up) */
    public function forget(string $name): void
    {
        $this->connection->executeStatement(\sprintf('DELETE FROM %s.check_result WHERE name = ?', $this->schema()), [$name]);
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }
}
