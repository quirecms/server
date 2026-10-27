<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Quire\Server\Database\SiteDatabases;
use Quire\Server\Site\SiteName;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sites' databases on a real PostgreSQL (the tests', as its administrative role).
 */
final class SiteDatabasesTest extends KernelTestCase
{
    private Connection $admin;
    private SiteDatabases $databases;
    private SiteName $jane;
    private SiteName $kim;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get(Connection::class);
        $this->databases = new SiteDatabases($this->admin);
        $suffix = bin2hex(random_bytes(3));
        $this->jane = new SiteName('t'.$suffix.'-jane');
        $this->kim = new SiteName('t'.$suffix.'-kim');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->databases->drop($this->jane);
        $this->databases->drop($this->kim);
        parent::tearDown();
    }

    public function testASiteGetsItsOwnDatabaseAndRoleAndNothingMore(): void
    {
        $password = $this->databases->create($this->jane);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}\z/', $password);
        self::assertTrue($this->databases->exists($this->jane));

        $jane = $this->connect($this->jane, $password, $this->jane->databaseIdentifier());
        self::assertSame($this->jane->databaseIdentifier(), $jane->fetchOne('SELECT current_user'));
        $jane->executeStatement('CREATE TABLE notes (id int)');

        /** @var array{rolsuper: bool, rolcreatedb: bool, rolcreaterole: bool, rolreplication: bool, rolbypassrls: bool} $role */
        $role = $this->admin->fetchAssociative('SELECT rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls FROM pg_roles WHERE rolname = ?', [$this->jane->databaseIdentifier()]);
        self::assertSame([false, false, false, false, false], array_values($role), 'Nothing beyond its own database.');
        $jane->close();
    }

    public function testThePasswordNeverReachesPostgresInPlainText(): void
    {
        $password = $this->databases->create($this->jane);

        $stored = $this->admin->fetchOne('SELECT rolpassword FROM pg_authid WHERE rolname = ?', [$this->jane->databaseIdentifier()]);
        self::assertIsString($stored);
        self::assertStringStartsWith('SCRAM-SHA-256$4096:', $stored);
        self::assertStringNotContainsString($password, $stored);
    }

    public function testASiteCantOpenAnotherSitesDatabase(): void
    {
        $janePassword = $this->databases->create($this->jane);
        $this->databases->create($this->kim);

        // (The server's own databases, e.g. "postgres", are closed by SiteDatabases::harden() at every
        // start; checked end to end, as hardening this test server would change it for everyone.)
        foreach ([$this->kim->databaseIdentifier()] as $database) {
            try {
                $this->connect($this->jane, $janePassword, $database)->fetchOne('SELECT 1');
                self::fail(\sprintf('Jane could open "%s".', $database));
            } catch (DbalException $e) {
                self::assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }

    public function testDroppingEndsConnectionsAndRemovesEverything(): void
    {
        $password = $this->databases->create($this->jane);
        $open = $this->connect($this->jane, $password, $this->jane->databaseIdentifier());
        $open->fetchOne('SELECT 1');

        $this->databases->drop($this->jane);

        self::assertFalse($this->databases->exists($this->jane));
        $this->databases->drop($this->jane);
        self::assertFalse($this->databases->exists($this->jane), 'Dropping twice is fine.');
    }

    public function testAnExistingDatabaseIsNeverTakenOver(): void
    {
        $this->databases->create($this->jane);

        $this->expectExceptionMessageIsOrContains('exists already');
        $this->databases->create($this->jane);
    }

    public function testTheVerifierHasPostgresFormat(): void
    {
        $verifier = SiteDatabases::scramVerifier('pencil', str_repeat("\x01", 16));

        // SCRAM-SHA-256$<iterations>:<salt>$<StoredKey>:<ServerKey>, base64 (keys of 32 bytes).
        self::assertSame(1, preg_match('/^SCRAM-SHA-256\$4096:([A-Za-z0-9+\/=]+)\$([A-Za-z0-9+\/=]+):([A-Za-z0-9+\/=]+)\z/', $verifier, $parts));
        self::assertSame(str_repeat("\x01", 16), base64_decode($parts[1], true));
        self::assertSame(32, \strlen((string) base64_decode($parts[2], true)));
        self::assertSame(32, \strlen((string) base64_decode($parts[3], true)));
        self::assertNotSame($verifier, SiteDatabases::scramVerifier('pencil'), 'A new salt each time.');
    }

    private function connect(SiteName $role, string $password, string $database): Connection
    {
        $params = $this->admin->getParams();

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => \is_string($params['host'] ?? null) ? $params['host'] : 'database',
            'port' => \is_int($params['port'] ?? null) ? $params['port'] : 5432,
            'user' => $role->databaseIdentifier(),
            'password' => $password,
            'dbname' => $database,
        ]);
    }
}
