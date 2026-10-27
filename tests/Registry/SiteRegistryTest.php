<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Registry;

use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;

final class SiteRegistryTest extends RegistryTestCase
{
    public function testSitesWithTheirDomainsPrimaryFirst(): void
    {
        $registry = new SiteRegistry($this->connection, $this->schema);
        $site = $registry->add(new SiteName('jane'), [new Domain('www.jane.example'), new Domain('jane.example')], Site::SHARED_DATABASE, 'jane');

        self::assertSame(Site::CREATING, $site->status);
        self::assertSame(['www.jane.example', 'jane.example'], array_map(strval(...), $site->domains), 'The primary first, as given.');
        self::assertSame([], $registry->servedDomains(), 'Not served while it\'s created.');

        $registry->setStatus($site->name, Site::RUNNING);
        self::assertSame(['jane.example' => 'jane', 'www.jane.example' => 'jane'], $registry->servedDomains());
        self::assertSame(['jane'], array_map(static fn (Site $s): string => $s->name->value, $registry->all()));
    }

    public function testNamesAndDomainsAreTakenOnce(): void
    {
        $registry = new SiteRegistry($this->connection, $this->schema);
        $registry->add(new SiteName('jane'), [new Domain('jane.example')], Site::SHARED_DATABASE, 'jane');

        try {
            $registry->add(new SiteName('jane'), [new Domain('other.example')], Site::SHARED_DATABASE, 'jane');
            self::fail('The name was taken twice.');
        } catch (\DomainException $e) {
            self::assertSame('There\'s already a site named "jane".', $e->getMessage());
        }

        try {
            $registry->add(new SiteName('kim'), [new Domain('kim.example'), new Domain('jane.example')], Site::SHARED_DATABASE, 'kim');
            self::fail('The domain was taken twice.');
        } catch (\DomainException $e) {
            self::assertSame('The domain "jane.example" belongs to the site "jane".', $e->getMessage());
        }

        self::assertNull($registry->get(new SiteName('kim')), 'All or nothing: kim isn\'t half added.');
    }

    public function testARemovedSitesNameAndDomainsAreFreeAgainAndItsHistoryStays(): void
    {
        $registry = new SiteRegistry($this->connection, $this->schema);
        $registry->add(new SiteName('jane'), [new Domain('jane.example')], Site::SHARED_DATABASE, 'jane');
        $registry->setStatus(new SiteName('jane'), Site::REMOVED);

        self::assertSame([], $registry->all());
        self::assertCount(1, $registry->all(withRemoved: true));

        $registry->add(new SiteName('kim'), [new Domain('jane.example')], Site::SHARED_DATABASE, 'kim');
        $again = $registry->add(new SiteName('jane'), [new Domain('jane2.example')], Site::SHARED_DATABASE, 'jane');
        self::assertSame(Site::CREATING, $again->status);
    }

    public function testOneOperationAtATimePerSite(): void
    {
        $lock = new SiteLock($this->connection);
        $params = $this->connection->getParams();
        // A second connection: another process, as far as PostgreSQL knows.
        $other = \Doctrine\DBAL\DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => \is_string($params['host'] ?? null) ? $params['host'] : 'database',
            'port' => \is_int($params['port'] ?? null) ? $params['port'] : 5432,
            'user' => \is_string($params['user'] ?? null) ? $params['user'] : 'app',
            'password' => \is_string($params['password'] ?? null) ? $params['password'] : '',
            'dbname' => \is_string($params['dbname'] ?? null) ? $params['dbname'] : 'app',
        ]);
        $jane = new SiteName('jane');
        $ran = [];

        $lock->run($jane, static function () use ($other, $jane, &$ran): void {
            $ran[] = 'jane';

            try {
                new SiteLock($other)->run($jane, static function () use (&$ran): void {
                    $ran[] = 'jane twice';
                });
                self::fail('Two operations ran on one site.');
            } catch (\DomainException $e) {
                self::assertStringContainsString('Something else is being done to the site "jane"', $e->getMessage());
            }

            new SiteLock($other)->run(new SiteName('kim'), static function () use (&$ran): void {
                $ran[] = 'kim';
            });
        });

        new SiteLock($other)->run($jane, static function () use (&$ran): void {
            $ran[] = 'jane again';
        });

        self::assertSame(['jane', 'kim', 'jane again'], $ran, 'Other sites aren\'t held up; the lock is released afterwards.');
        $other->close();
    }
}
