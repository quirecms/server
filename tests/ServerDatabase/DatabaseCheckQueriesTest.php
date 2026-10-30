<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerDatabase;

use Quire\Server\ServerDatabase\DatabaseCheck;
use Quire\Server\Tests\Registry\RegistryTestCase;

/**
 * DatabaseCheck's queries on a real PostgreSQL, as its superuser (the upgrade runs them so): each one
 * runs, and lists what a site's database has.
 */
final class DatabaseCheckQueriesTest extends RegistryTestCase
{
    public function testTheInventoryOfADatabase(): void
    {
        $schema = $this->connection->quoteSingleIdentifier($this->schema);

        foreach ([
            "CREATE TABLE {$schema}.content (id serial PRIMARY KEY, body text NOT NULL CHECK (body <> ''))",
            "INSERT INTO {$schema}.content (body) SELECT 'post ' || g FROM generate_series(1, 7) g",
            "CREATE VIEW {$schema}.recent AS SELECT * FROM {$schema}.content",
            "CREATE TABLE {$schema}.parted (k int) PARTITION BY RANGE (k)",
            "CREATE TABLE {$schema}.parted_a PARTITION OF {$schema}.parted FOR VALUES FROM (0) TO (10)",
            "CREATE FUNCTION {$schema}.touch() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NEW; END'",
            "CREATE TRIGGER touched BEFORE UPDATE ON {$schema}.content FOR EACH ROW EXECUTE FUNCTION {$schema}.touch()",
            "ALTER TABLE {$schema}.content ENABLE ROW LEVEL SECURITY",
            "CREATE POLICY mine ON {$schema}.content USING (true)",
        ] as $statement) {
            $this->connection->executeStatement($statement);
        }

        $lines = $this->connection->fetchFirstColumn(DatabaseCheck::DATABASE);
        $ours = array_values(array_filter($lines, fn (mixed $line): bool => \is_string($line) && str_contains($line, $this->schema.'.')));

        foreach ([
            \sprintf('relation %s.content r rows 7', $this->schema),
            \sprintf('relation %s.content_pkey i', $this->schema),
            \sprintf('relation %s.recent v', $this->schema),
            \sprintf('relation %s.parted p', $this->schema),
            \sprintf('relation %s.parted_a r rows 0', $this->schema),
            \sprintf('sequence %s.content_id_seq 7', $this->schema),
            \sprintf('function %s.touch()', $this->schema),
            \sprintf('constraint %s.content_body_check c', $this->schema),
            \sprintf('constraint %s.content_pkey p', $this->schema),
            \sprintf('trigger %s.content.touched', $this->schema),
            \sprintf('policy %s.content.mine', $this->schema),
        ] as $expected) {
            self::assertContains($expected, $ours);
        }

        // The registry's tables are in the same schema, empty
        self::assertSame(7, DatabaseCheck::counts(implode("\n", $ours))['rows']);
    }

    public function testTheClusterQueriesRun(): void
    {
        $cluster = $this->connection->fetchFirstColumn(DatabaseCheck::CLUSTER);
        self::assertNotEmpty(array_filter($cluster, static fn (mixed $line): bool => \is_string($line) && str_starts_with($line, 'role postgres ')));
        self::assertNotEmpty($this->connection->fetchFirstColumn(DatabaseCheck::DATABASES));
        self::assertTrue(is_numeric($this->connection->fetchOne(DatabaseCheck::POSTGRES_DATABASE)));
        self::assertTrue(is_numeric($this->connection->fetchOne(DatabaseCheck::SIZE)));
    }
}
