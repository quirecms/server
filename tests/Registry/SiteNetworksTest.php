<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Registry;

use Doctrine\DBAL\Exception\DriverException;
use Quire\Server\Network\Ipv4Cidr;
use Quire\Server\Network\SiteNetworkRange;
use Quire\Server\Network\SiteSubnets;
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;

final class SiteNetworksTest extends RegistryTestCase
{
    private SiteNetworks $networks;
    private SiteNetworkRange $range;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->networks = new SiteNetworks($this->connection, $this->schema);
        $this->range = new SiteNetworkRange('10.72.0.0/22');

        foreach (['jane', 'ada', 'kim', 'lee'] as $name) {
            new SiteRegistry($this->connection, $this->schema)->add(new SiteName($name), [new Domain($name.'.example')], Site::SHARED_DATABASE, $name);
        }
    }

    public function testEachSiteGetsTheFirstFreeBlockAndKeepsIt(): void
    {
        $jane = $this->networks->allocate(new SiteName('jane'), $this->range, []);
        $ada = $this->networks->allocate(new SiteName('ada'), $this->range, []);

        self::assertSame(['10.72.0.0/26', '10.72.0.64/26'], [(string) $jane->block, (string) $ada->block]);
        self::assertSame('10.72.0.0/26', (string) $this->networks->allocate(new SiteName('jane'), $this->range, [])->block, 'Asked again, the same.');
        self::assertSame('10.72.0.0/26', (string) $this->networks->find(new SiteName('jane'))?->block);
        self::assertNull($this->networks->find(new SiteName('kim')));
        self::assertSame(['jane', 'ada'], array_keys($this->networks->all()), 'In address order.');
    }

    public function testARemovedSitesAddressesAreFreeAgain(): void
    {
        $this->networks->allocate(new SiteName('jane'), $this->range, []);
        $this->networks->allocate(new SiteName('ada'), $this->range, []);
        $this->networks->release(new SiteName('jane'));

        self::assertSame('10.72.0.0/26', (string) $this->networks->allocate(new SiteName('kim'), $this->range, [])->block, 'The gap is used first.');
    }

    public function testAddressesDockerNetworksOverlapAreLeftOut(): void
    {
        $docker = ['bridge' => [Ipv4Cidr::parse('172.17.0.0/16')], 'vpn' => [Ipv4Cidr::parse('10.72.0.0/25')], 'other' => [Ipv4Cidr::parse('10.72.0.160/27')]];

        self::assertSame('10.72.0.192/26', (string) $this->networks->allocate(new SiteName('jane'), $this->range, $docker)->block, 'Past the three blocks those two overlap.');
    }

    public function testAFullRangeSaysWhatTakesIt(): void
    {
        $range = new SiteNetworkRange('10.72.0.0/22');
        $docker = ['vpn' => [Ipv4Cidr::parse('10.72.1.0/24'), Ipv4Cidr::parse('10.72.2.0/23')]];
        $this->networks->allocate(new SiteName('jane'), $range, $docker);
        $this->networks->allocate(new SiteName('ada'), $range, $docker);
        $this->networks->allocate(new SiteName('kim'), $range, $docker);
        $this->networks->allocate(new SiteName('lee'), $range, $docker);
        new SiteRegistry($this->connection, $this->schema)->add(new SiteName('max'), [new Domain('max.example')], Site::SHARED_DATABASE, 'max');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('No addresses are left for a site in 10.72.0.0/22 (QUIRE_SERVER_SITE_NETWORKS): 4 sites have theirs, and other Docker networks on this machine overlap 12 more (vpn 10.72.1.0/24, vpn 10.72.2.0/23).');
        $this->networks->allocate(new SiteName('max'), $range, $docker);
    }

    public function testPostgresqlRefusesTwoSitesOverlappingAddresses(): void
    {
        $this->networks->allocate(new SiteName('jane'), $this->range, []);

        $this->expectException(DriverException::class);
        $this->connection->insert($this->connection->quoteSingleIdentifier($this->schema).'.site_network', ['site' => 'ada', 'block' => '10.72.0.0/26']);
    }

    public function testARemovedSiteRowTakesItsAddressesAlong(): void
    {
        $this->networks->allocate(new SiteName('jane'), $this->range, []);
        $this->connection->delete($this->connection->quoteSingleIdentifier($this->schema).'.site', ['name' => 'jane']);

        self::assertNull($this->networks->find(new SiteName('jane')));
    }

    public function testOnlyASitesOwnSizeIsKept(): void
    {
        $this->expectException(DriverException::class);
        $this->connection->insert($this->connection->quoteSingleIdentifier($this->schema).'.site_network', ['site' => 'jane', 'block' => '10.72.0.0/24']);
    }

    public function testASitesBlockSplitsIntoItsTwoNetworks(): void
    {
        $subnets = new SiteSubnets(Ipv4Cidr::parse('10.72.3.192/26'));

        self::assertSame(['10.72.3.192/27', '10.72.3.224/27', '10.72.3.194', '10.72.3.195'], [(string) $subnets->link, (string) $subnets->project, $subnets->frontDoor(), $subnets->database()]);
    }
}
