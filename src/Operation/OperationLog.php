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
    /** Why an operation the agent's stop cut short failed, and where to look */
    public const string INTERRUPTED = 'Interrupted: the agent stopped while it ran. What it left half done was put back when the agent started again ("quire-server logs agent" says how).';

    /** Settings whose values are secrets, by their name's end */
    private const string SECRET = '/(?:PASSWORD|SECRET|_KEY|TOKEN|_DSN|_URL)\z/';

    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
        /** Where sites' folders are: an operation on a site is scrubbed of its prod.env's secrets too */
        private ?\Quire\Server\Site\ServerPaths $paths = null,
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

        $operation = new Operation($id, $action, $site, $this->append(...), $this->finish(...));

        // Every operation is scrubbed of the agent's own secrets, and an operation on a site of the
        // site's: whatever a program prints (ISO-10, MAINT-09), not only what its caller thought of
        foreach ($this->secrets($site) as $secret) {
            $operation->secret($secret);
        }

        return $operation;
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
     * An operation's whole ID from its first characters (8 at least, as "operations" shows them),
     * or null when none (or more than one) starts so.
     */
    public function resolve(string $prefix): ?string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}(?:[0-9a-f-]{0,28})\z/', $prefix)) {
            return null;
        }

        $ids = $this->connection->fetchFirstColumn(\sprintf('SELECT id FROM %s.operation WHERE id::text LIKE ? LIMIT 2', $this->schema()), [$prefix.'%']);

        return 1 === \count($ids) && \is_string($ids[0]) ? $ids[0] : null;
    }

    /**
     * @return list<string>
     */
    public function lines(string $operationId): array
    {
        return array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $this->connection->fetchFirstColumn(\sprintf('SELECT line FROM %s.operation_log WHERE operation_id = ? ORDER BY seq', $this->schema()), [$operationId]));
    }

    /**
     * The operations that run now, e.g. "site.deploy of blog".
     *
     * @return list<string>
     */
    public function running(): array
    {
        return array_map(
            static fn (array $row): string => self::text($row['action'] ?? '').(null === ($row['site'] ?? null) ? '' : ' of '.self::text($row['site'])),
            $this->connection->fetchAllAssociative(\sprintf("SELECT action, site FROM %s.operation WHERE status = 'running' ORDER BY started_at", $this->schema())),
        );
    }

    /**
     * When an action last started, or null when it never did.
     */
    public function lastStarted(string $action): ?\DateTimeImmutable
    {
        $at = $this->connection->fetchOne(\sprintf('SELECT max(started_at) FROM %s.operation WHERE action = ?', $this->schema()), [$action]);

        return \is_string($at) ? new \DateTimeImmutable($at) : null;
    }

    /**
     * Operations a crash or a restart left "running": marked failed, so none looks busy forever.
     */
    public function abandonRunning(): int
    {
        return (int) $this->connection->executeStatement(\sprintf("UPDATE %s.operation SET status = 'failed', error = ?, finished_at = now() WHERE status = 'running'", $this->schema()), [self::INTERRUPTED]);
    }

    /**
     * The agent's secrets (its environment's passwords, keys and the passwords in its addresses), and
     * the site's (its prod.env's).
     *
     * @return list<string>
     */
    private function secrets(?SiteName $site): array
    {
        $values = [];

        foreach (getenv() as $name => $value) {
            if (1 === preg_match(self::SECRET, $name)) {
                $values[] = $value;
            }
        }

        if ($site instanceof SiteName && $this->paths instanceof \Quire\Server\Site\ServerPaths) {
            $file = $this->paths->site($site).'/'.\Quire\Server\Site\SiteFiles::ENV_FILE;

            try {
                foreach (is_file($file) && !is_link($file) ? \Quire\Server\Site\EnvFile::read($file)->values() : [] as $name => $value) {
                    if (1 === preg_match(self::SECRET, $name)) {
                        $values[] = $value;
                    }
                }
            } catch (\Throwable) {
                // An env file it can't read has nothing it can scrub
            }
        }

        $secrets = [];

        foreach ($values as $value) {
            // An address: its password is the secret (the rest of it, e.g. a host, is fine in a log)
            $password = 1 === preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) ? parse_url($value, \PHP_URL_PASS) : $value;

            if (\is_string($password) && '' !== $password) {
                $secrets[] = \is_string(parse_url($value, \PHP_URL_PASS)) ? rawurldecode($password) : $password;
            }
        }

        return array_values(array_unique($secrets));
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
