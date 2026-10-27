<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Registry;

use Doctrine\DBAL\Connection;
use Quire\Server\Registry\Schema;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests on the registry's real tables, in a schema of their own that's dropped afterwards.
 */
abstract class RegistryTestCase extends KernelTestCase
{
    protected Connection $connection;
    protected string $schema;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->schema = 'server_test_'.bin2hex(random_bytes(4));

        foreach ([...Schema::version1($this->schema), ...Schema::version2($this->schema)] as $statement) {
            // The index names are per schema in PostgreSQL: no clash between test runs.
            $this->connection->executeStatement($statement);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->executeStatement(\sprintf('DROP SCHEMA %s CASCADE', $this->connection->quoteSingleIdentifier($this->schema)));
        parent::tearDown();
    }
}
