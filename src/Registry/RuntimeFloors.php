<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Doctrine\DBAL\Connection;

/**
 * The newest build of each of Quire's runtime images (by the tag a site names) this server built on:
 * a floor that only rises. A signature never expires, so a tag moved back to an older signed build
 * would otherwise roll every site onto it, with its known flaws.
 */
final readonly class RuntimeFloors
{
    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    /**
     * @return array{digest: string, created: \DateTimeImmutable}|null
     */
    public function of(string $image): ?array
    {
        $row = $this->connection->fetchAssociative(\sprintf('SELECT digest, created FROM %s.runtime_floor WHERE image = ?', $this->table()), [$image]);

        return false === $row || !\is_string($row['digest']) || !\is_string($row['created']) ? null : ['digest' => $row['digest'], 'created' => new \DateTimeImmutable($row['created'])];
    }

    /**
     * Raises the floor to this build, unless it's higher already.
     */
    public function raise(string $image, string $digest, \DateTimeImmutable $created): void
    {
        $this->connection->executeStatement(
            \sprintf('INSERT INTO %1$s.runtime_floor (image, digest, created) VALUES (?, ?, ?) ON CONFLICT (image) DO UPDATE SET digest = EXCLUDED.digest, created = EXCLUDED.created, seen_at = now() WHERE %1$s.runtime_floor.created < EXCLUDED.created', $this->table()),
            [$image, $digest, $created->format(\DateTimeInterface::RFC3339)],
        );
    }

    private function table(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }
}
