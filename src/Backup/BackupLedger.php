<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Doctrine\DBAL\Connection;
use Quire\Server\Registry\Schema;

/**
 * The backups off the server this server made (D-36), as its registry keeps them: no one with only
 * the repository's password writes here. A restore on this server takes only one of these (or one a
 * server it trusts signed); the repository's watch (M-13) checks they're all still there. And the
 * public keys of other servers the owner told it to trust (a site moved, a whole server restored).
 *
 * @phpstan-type Record array{id: string, host: string, kind: string, site: ?string, created_at: string}
 */
final readonly class BackupLedger
{
    public const string SITE = 'site';
    public const string SERVER = 'server';

    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    public function record(string $repository, string $id, string $host, string $kind, ?string $site, \DateTimeImmutable $created): void
    {
        $this->connection->executeStatement(
            \sprintf('INSERT INTO %s.backup_snapshot (id, repository, host, kind, site, created_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING', $this->schema()),
            [$id, $repository, $host, $kind, $site, $created->format(\DATE_ATOM)],
        );
    }

    /**
     * The retention forgot these: they're no longer this server's to find.
     *
     * @param list<string> $ids
     */
    public function forgotten(array $ids): void
    {
        foreach ($ids as $id) {
            $this->connection->executeStatement(\sprintf('UPDATE %s.backup_snapshot SET forgotten_at = now() WHERE id = ? AND forgotten_at IS NULL', $this->schema()), [$id]);
        }
    }

    /**
     * The snapshots of a server it made in a repository and hasn't forgotten, by ID.
     *
     * @return array<string, Record>
     */
    public function kept(string $repository, string $host): array
    {
        $kept = [];

        foreach ($this->connection->fetchAllAssociative(\sprintf('SELECT id, host, kind, site, created_at FROM %s.backup_snapshot WHERE repository = ? AND host = ? AND forgotten_at IS NULL ORDER BY created_at', $this->schema()), [$repository, $host]) as $row) {
            $record = $this->toRecord($row);
            $kept[$record['id']] = $record;
        }

        return $kept;
    }

    public function trust(string $publicKey, string $host): void
    {
        $this->connection->executeStatement(\sprintf('INSERT INTO %s.backup_key (public_key, host) VALUES (?, ?) ON CONFLICT (public_key) DO UPDATE SET host = excluded.host', $this->schema()), [$publicKey, $host]);
    }

    /**
     * @return list<string> The public keys trusted for a server's backups
     */
    public function trusted(string $host): array
    {
        return array_values(array_filter($this->connection->fetchFirstColumn(\sprintf('SELECT public_key FROM %s.backup_key WHERE host = ? ORDER BY trusted_at', $this->schema()), [$host]), is_string(...)));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return Record
     */
    private function toRecord(array $row): array
    {
        return [
            'id' => \is_string($row['id'] ?? null) ? $row['id'] : '',
            'host' => \is_string($row['host'] ?? null) ? $row['host'] : '',
            'kind' => \is_string($row['kind'] ?? null) ? $row['kind'] : '',
            'site' => \is_string($row['site'] ?? null) ? $row['site'] : null,
            'created_at' => \is_string($row['created_at'] ?? null) ? new \DateTimeImmutable($row['created_at'])->format(\DATE_ATOM) : '',
        ];
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }
}
