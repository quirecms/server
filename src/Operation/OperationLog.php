<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Quire\Server\Registry\Schema;
use Quire\Server\Site\SiteName;

/**
 * Records every operation on the server: what, on which site, from where and by whom, its outcome,
 * and its log. The record is written as the operation goes, so one that's interrupted (a crash, a
 * restart) is seen as such.
 */
final readonly class OperationLog
{
    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $parameters What was asked, without secrets
     */
    public function start(string $action, ?SiteName $site, array $parameters, string $channel, string $requestedBy): Operation
    {
        $id = $this->uuid();
        $this->connection->insert($this->table('operation'), [
            'id' => $id,
            'action' => $action,
            'site' => $site?->value,
            'parameters' => $parameters,
            'channel' => $channel,
            'requested_by' => $requestedBy,
            'status' => 'running',
        ], ['parameters' => Types::JSON]);

        return new Operation($id, $action, $site, $this->append(...), $this->finish(...));
    }

    /**
     * The latest operations, newest first.
     *
     * @return list<array{id: string, action: string, site: ?string, channel: string, requested_by: string, status: string, error: ?string, started_at: string, finished_at: ?string}>
     */
    public function latest(?SiteName $site = null, int $limit = 20): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'action', 'site', 'channel', 'requested_by', 'status', 'error', 'started_at', 'finished_at')
            ->from($this->table('operation'))
            ->orderBy('started_at', 'DESC')
            ->setMaxResults($limit);

        if ($site instanceof SiteName) {
            $query->where('site = :site')->setParameter('site', $site->value);
        }

        return array_map(static fn (array $row): array => [
            'id' => self::text($row['id'] ?? ''),
            'action' => self::text($row['action'] ?? ''),
            'site' => null === ($row['site'] ?? null) ? null : self::text($row['site']),
            'channel' => self::text($row['channel'] ?? ''),
            'requested_by' => self::text($row['requested_by'] ?? ''),
            'status' => self::text($row['status'] ?? ''),
            'error' => null === ($row['error'] ?? null) ? null : self::text($row['error']),
            'started_at' => self::text($row['started_at'] ?? ''),
            'finished_at' => null === ($row['finished_at'] ?? null) ? null : self::text($row['finished_at']),
        ], $query->executeQuery()->fetchAllAssociative());
    }

    /**
     * @return list<string>
     */
    public function lines(string $operationId): array
    {
        return array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $this->connection->fetchFirstColumn(\sprintf('SELECT line FROM %s.operation_log WHERE operation_id = ? ORDER BY seq', $this->schema()), [$operationId]));
    }

    /**
     * Operations a crash or a restart left "running": marked failed, so none looks busy forever.
     */
    public function abandonRunning(): int
    {
        return (int) $this->connection->executeStatement(\sprintf("UPDATE %s.operation SET status = 'failed', error = 'Interrupted: the agent stopped while it ran.', finished_at = now() WHERE status = 'running'", $this->schema()));
    }

    private function append(string $id, int $seq, string $line): void
    {
        $this->connection->insert($this->table('operation_log'), ['operation_id' => $id, 'seq' => $seq, 'line' => $line]);
    }

    private function finish(string $id, bool $succeeded, ?string $error): void
    {
        $this->connection->executeStatement(\sprintf('UPDATE %s.operation SET status = ?, error = ?, finished_at = now() WHERE id = ?', $this->schema()), [$succeeded ? 'succeeded' : 'failed', $error, $id]);
    }

    private function table(string $table): string
    {
        return $this->schema().'.'.$table;
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }

    /**
     * A version 4 UUID.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
