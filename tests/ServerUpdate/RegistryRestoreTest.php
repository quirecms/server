<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerUpdate;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Quire\Server\Registry\Schema;
use Quire\Server\ServerUpdate\ServerStack;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * The script that restores the registry (ServerStack::RESTORE), run with PostgreSQL's own tools on a
 * real PostgreSQL, in a database of its own: an older release's registry restored over a newer one, as
 * an undo or the rollback of a failed update does it.
 */
final class RegistryRestoreTest extends KernelTestCase
{
    private Connection $admin;
    /** @var array{host?: string, port?: int, password?: string} */
    private array $params;
    private Connection $connection;
    private string $database;
    private string $dump;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $admin = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $admin);
        $this->admin = $admin;
        $this->database = 'restore_test_'.bin2hex(random_bytes(4));
        $this->admin->executeStatement(\sprintf('CREATE DATABASE %s', $this->database));
        // The test database's server, as its own address says
        $url = $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($url);
        $params = new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url);
        $this->params = $params;
        $this->connection = DriverManager::getConnection(['dbname' => $this->database] + $params);
        $this->dump = (string) tempnam(sys_get_temp_dir(), 'registry-dump-');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->close();
        $this->admin->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $this->database));
        @unlink($this->dump);
        parent::tearDown();
    }

    public function testAnOlderRegistryIsRestoredOverANewerOneWhole(): void
    {
        // 0.3.1's registry (its 6 migrations), with a site, dumped as the updater backs it up
        $this->migrate(1, 6);
        $this->connection->executeStatement("INSERT INTO server.site (name, status, database_mode, project) VALUES ('jane', 'running', 'shared', 'jane')");
        $this->dumpDatabase();
        // Then 0.4.0's: its migrations add tables with keys on the older ones (site_network on site)
        $this->migrate(7, 11);
        $this->connection->executeStatement("INSERT INTO server.site (name, status, database_mode, project) VALUES ('kim', 'running', 'shared', 'kim')");
        $this->connection->executeStatement("INSERT INTO server.site_network (site, block) VALUES ('kim', '10.72.0.64/26')");
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('server.site_network')"));
        $public = $this->publicSchema();

        $restore = $this->restore($this->dump);

        self::assertTrue($restore->isSuccessful(), $restore->getErrorOutput());
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('server.site_network')"), 'What the dump doesn\'t know is gone.');
        self::assertSame(['jane'], $this->connection->fetchFirstColumn('SELECT name FROM server.site ORDER BY name'));
        self::assertSame(['1', '2', '3', '4', '5', '6'], $this->connection->fetchFirstColumn('SELECT version FROM public.doctrine_migration_versions ORDER BY version::int'));
        self::assertSame($public, $this->publicSchema(), 'The public schema is made again as PostgreSQL makes it.');
    }

    public function testADumpThatCantBeReadWholeChangesNothing(): void
    {
        $this->migrate(1, 6);
        $this->dumpDatabase();
        $this->migrate(7, 11);
        $this->connection->executeStatement("INSERT INTO server.site (name, status, database_mode, project) VALUES ('kim', 'running', 'shared', 'kim')");
        $whole = (string) file_get_contents($this->dump);
        file_put_contents($this->dump, substr($whole, 0, intdiv(\strlen($whole), 2)));

        $restore = $this->restore($this->dump);

        self::assertFalse($restore->isSuccessful());
        self::assertStringContainsString('nothing was restored', $restore->getErrorOutput());
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('server.site_network')"), 'The newer registry is as it was.');
        self::assertSame(['kim'], $this->connection->fetchFirstColumn('SELECT name FROM server.site'));
        self::assertEquals(11, $this->connection->fetchOne('SELECT count(*) FROM public.doctrine_migration_versions'));
    }

    public function testADumpThatFailsToRestoreChangesNothing(): void
    {
        $this->migrate(1, 11);
        $this->connection->executeStatement("INSERT INTO server.site (name, status, database_mode, project) VALUES ('kim', 'running', 'shared', 'kim')");
        file_put_contents($this->dump, "PGDMP but not a dump\n");

        $restore = $this->restore($this->dump);

        self::assertFalse($restore->isSuccessful());
        self::assertSame(['kim'], $this->connection->fetchFirstColumn('SELECT name FROM server.site'));
    }

    /**
     * The registry's migrations from one version to another, as Doctrine's migrations record them.
     */
    private function migrate(int $from, int $to): void
    {
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS public.doctrine_migration_versions (version VARCHAR(191) PRIMARY KEY, executed_at TIMESTAMP(0), execution_time INT)');

        $versions = [1 => Schema::version1(...), Schema::version2(...), Schema::version3(...), Schema::version4(...), Schema::version5(...), Schema::version6(...), Schema::version7(...), Schema::version8(...), Schema::version9(...), Schema::version10(...), Schema::version11(...)];

        foreach (range($from, $to) as $version) {
            foreach ($versions[$version]() as $statement) {
                $this->connection->executeStatement($statement);
            }

            $this->connection->executeStatement('INSERT INTO public.doctrine_migration_versions (version) VALUES (?)', [(string) $version]);
        }
    }

    private function dumpDatabase(): void
    {
        $dump = new Process(['pg_dump', '--username=postgres', '--format=custom', '--file='.$this->dump, $this->database], null, $this->environment());
        $dump->mustRun();
    }

    private function restore(string $dump): Process
    {
        $restore = new Process(['sh', '-c', ServerStack::RESTORE, 'restore-registry', $this->database], null, $this->environment(), (string) file_get_contents($dump), 60);
        $restore->run();

        return $restore;
    }

    /**
     * The public schema's owner and rights.
     */
    private function publicSchema(): string
    {
        $public = $this->connection->fetchOne("SELECT pg_get_userbyid(nspowner) || ' ' || coalesce(nspacl::text, '') FROM pg_namespace WHERE nspname = 'public'");
        self::assertIsString($public);

        return $public;
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return ['PGHOST' => $this->params['host'] ?? '', 'PGPORT' => (string) ($this->params['port'] ?? 5432), 'PGPASSWORD' => $this->params['password'] ?? ''];
    }
}
