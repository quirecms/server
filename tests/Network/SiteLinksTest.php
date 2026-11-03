<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Network;

use Quire\Server\Network\Ipv4Cidr;
use Quire\Server\Network\SiteLinks;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\GateFixture;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;

/**
 * Sites' links on a Docker that keeps what it's told: its networks, and which containers are on them
 * at which address.
 */
final class SiteLinksTest extends RegistryTestCase
{
    /** @var array<string, array{subnet: string, internal: bool, label: string}> */
    private array $networks = [];

    /** @var array<string, array<string, array{ip: string, aliases: list<string>}>> Container => network => attachment */
    private array $containers = [];

    private int $reloads = 0;
    private string $access;
    private ScriptedRunner $runner;
    private SiteLinks $links;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // jane on the server's PostgreSQL; demo, adopted, with its own
        new SiteRegistry($this->connection, $this->schema)->add(new SiteName('jane'), [new Domain('jane.example')], Site::SHARED_DATABASE, 'jane');
        new SiteRegistry($this->connection, $this->schema)->add(new SiteName('demo'), [new Domain('demo.example')], Site::OWN_DATABASE, 'demo');

        $this->containers = [SiteLinks::FRONT_DOOR => ['quire-edge' => ['ip' => '172.18.0.2', 'aliases' => []]], SiteLinks::DATABASE => ['quire-data' => ['ip' => '172.19.0.2', 'aliases' => ['quire-database']]]];
        $this->networks = ['bridge' => ['subnet' => '172.17.0.0/16', 'internal' => false, 'label' => ''], 'quire-data' => ['subnet' => '172.19.0.0/16', 'internal' => false, 'label' => '']];
        $this->runner = new ScriptedRunner([
            'docker network ls' => fn (array $command): ProcessResult => ScriptedRunner::ok($command, implode("\n", array_keys($this->networks))."\n"),
            'docker network inspect' => function (array $command): ProcessResult {
                $names = \array_slice($command, 5);

                if ('{{.Name}}' === $command[4] || '{{range .IPAM.Config}}{{.Subnet}} {{end}}' === $command[4]) {
                    return isset($this->networks[$names[0]]) ? ScriptedRunner::ok($command, ('{{.Name}}' === $command[4] ? $names[0] : $this->networks[$names[0]]['subnet'].' ')."\n") : ScriptedRunner::failed($command, 'network '.$names[0].' not found');
                }

                return ScriptedRunner::ok($command, implode("\n", array_map(fn (string $name): string => $name.' '.$this->networks[$name]['subnet'], $names))."\n");
            },
            'docker network create' => function (array $command): ProcessResult {
                $name = (string) end($command);
                $this->networks[$name] = ['subnet' => $this->option($command, '--subnet'), 'internal' => \in_array('--internal', $command, true), 'label' => $this->option($command, '--label')];
                self::assertSame(Ipv4Cidr::parse($this->option($command, '--subnet'))->address(1), $this->option($command, '--gateway'), 'Docker\'s gateway takes .1, as the plan has it.');
                self::assertSame(Ipv4Cidr::parse($this->option($command, '--subnet'))->address(16).'/28', $this->option($command, '--ip-range'), 'The site\'s containers get .16 to .31: never the front door\'s .2 or PostgreSQL\'s .3, even while they\'re off it.');

                return ScriptedRunner::ok($command);
            },
            'docker network rm' => function (array $command): ProcessResult {
                self::assertSame([], array_filter($this->containers, static fn (array $networks): bool => isset($networks[(string) end($command)])), 'Nothing is on it any more.');
                unset($this->networks[(string) end($command)]);

                return ScriptedRunner::ok($command);
            },
            'docker network connect' => function (array $command): ProcessResult {
                $aliases = [];

                foreach ($command as $index => $part) {
                    if ('--alias' === $part) {
                        $aliases[] = $command[$index + 1];
                    }
                }

                $this->containers[$command[\count($command) - 1]][$command[\count($command) - 2]] = ['ip' => $this->option($command, '--ip'), 'aliases' => $aliases];

                return ScriptedRunner::ok($command);
            },
            'docker network disconnect' => function (array $command): ProcessResult {
                unset($this->containers[$command[\count($command) - 1]][$command[\count($command) - 2]]);

                return ScriptedRunner::ok($command);
            },
            'docker kill --signal' => function (array $command): ProcessResult {
                ++$this->reloads;

                return ScriptedRunner::ok($command);
            },
            'docker container inspect' => fn (array $command): ProcessResult => isset($this->containers[(string) end($command)])
                ? ScriptedRunner::ok($command, implode(' ', array_keys($this->containers[(string) end($command)]))." \n")
                : ScriptedRunner::failed($command, 'No such container'),
        ]);
        $this->access = sys_get_temp_dir().'/quire-access-links-'.bin2hex(random_bytes(6));
        $this->links = GateFixture::links($this->runner, $this->connection, $this->schema, $this->access);
    }

    public function testASitesLinkIsItsOwnWithTheFrontDoorAndPostgresqlAtTheirAddresses(): void
    {
        $subnets = $this->links->ensure(new SiteName('jane'), 'jane', true);

        self::assertSame(['subnet' => '10.72.0.0/27', 'internal' => true, 'label' => SiteLinks::LABEL.'=jane'], $this->networks['quire-link-jane'], 'No way out: the site\'s own network has that.');
        self::assertSame(['ip' => '10.72.0.2', 'aliases' => []], $this->containers[SiteLinks::FRONT_DOOR]['quire-link-jane']);
        self::assertSame(['ip' => '10.72.0.3', 'aliases' => ['quire-database']], $this->containers[SiteLinks::DATABASE]['quire-link-jane'], 'By the name sites\' DATABASE_URL has.');
        self::assertSame('10.72.0.2', $subnets->frontDoor());

        $commands = \count($this->runner->runs);
        $this->links->ensure(new SiteName('jane'), 'jane', true);
        self::assertSame(['docker network ls', 'docker network inspect', 'docker container inspect', 'docker container inspect'], \array_slice($this->runner->keys(), $commands), 'Done once: checked again, nothing changed.');
    }

    public function testASiteWithItsOwnPostgresqlDoesntGetTheServers(): void
    {
        $this->links->ensure(new SiteName('demo'), 'demo', false);

        self::assertArrayHasKey('quire-link-demo', $this->containers[SiteLinks::FRONT_DOOR]);
        self::assertArrayNotHasKey('quire-link-demo', $this->containers[SiteLinks::DATABASE]);
    }

    public function testSitesGetBlocksApartAndNotWhatDockerHasAlready(): void
    {
        $this->networks['vpn'] = ['subnet' => '10.72.0.0/26', 'internal' => false, 'label' => ''];

        $jane = $this->links->ensure(new SiteName('jane'), 'jane', true);
        $demo = $this->links->ensure(new SiteName('demo'), 'demo', true);

        self::assertSame(['10.72.0.64/27', '10.72.0.128/27'], [(string) $jane->link, (string) $demo->link]);
    }

    public function testServerContainersNotMadeYetAreLeftToItsComposeFiles(): void
    {
        $this->containers = [];

        $this->links->ensure(new SiteName('jane'), 'jane', true);

        self::assertArrayHasKey('quire-link-jane', $this->networks);
        self::assertNotContains('docker network connect', $this->runner->keys());
    }

    public function testALinkWithOtherAddressesIsntTakenForTheSites(): void
    {
        $this->networks['quire-link-jane'] = ['subnet' => '10.99.0.0/24', 'internal' => false, 'label' => ''];

        $this->expectExceptionMessageIsOrContains('The network "quire-link-jane" has the addresses 10.99.0.0/24, not the site\'s (10.72.0.0/27): remove it ("docker network rm quire-link-jane") and try again.');
        $this->links->ensure(new SiteName('jane'), 'jane', true);
    }

    public function testRemovingALinkTakesTheServerOffItAndFreesItsAddresses(): void
    {
        $this->links->ensure(new SiteName('jane'), 'jane', true);
        $this->links->remove(new SiteName('jane'), 'jane');

        self::assertArrayNotHasKey('quire-link-jane', $this->networks);
        self::assertArrayNotHasKey('quire-link-jane', $this->containers[SiteLinks::FRONT_DOOR]);
        self::assertNull(new SiteNetworks($this->connection, $this->schema)->find(new SiteName('jane')));

        $this->links->remove(new SiteName('jane'), 'jane');
        self::assertSame('docker network inspect', array_last($this->runner->keys()), 'Removed already: nothing more to do.');
    }

    public function testTheServersContainersMadeAgainAreBackOnEverySitesLink(): void
    {
        $this->links->ensure(new SiteName('jane'), 'jane', true);
        $this->links->ensure(new SiteName('demo'), 'demo', false);
        // "quire-server up" made the front door and PostgreSQL again: their networks are the Compose files' only
        $this->containers[SiteLinks::FRONT_DOOR] = ['quire-edge' => ['ip' => '172.18.0.2', 'aliases' => []]];
        $this->containers[SiteLinks::DATABASE] = ['quire-data' => ['ip' => '172.19.0.2', 'aliases' => ['quire-database']]];
        $registry = new SiteRegistry($this->connection, $this->schema);

        self::assertSame(['demo: the front door back on its link', 'jane: the front door, PostgreSQL back on its link', 'the server\'s logins to PostgreSQL from its network written again'], $this->links->reconcile($registry->all()), 'Its first round writes the server\'s logins too (the agent\'s start does, before it).');
        self::assertSame(['ip' => '10.72.0.2', 'aliases' => []], $this->attachment(SiteLinks::FRONT_DOOR, 'quire-link-jane'));
        self::assertSame(['ip' => '10.72.0.66', 'aliases' => []], $this->attachment(SiteLinks::FRONT_DOOR, 'quire-link-demo'));
        self::assertSame(['ip' => '10.72.0.3', 'aliases' => ['quire-database']], $this->attachment(SiteLinks::DATABASE, 'quire-link-jane'));
        self::assertNull($this->attachment(SiteLinks::DATABASE, 'quire-link-demo'), 'demo keeps its own PostgreSQL.');

        $commands = \count($this->runner->runs);
        self::assertSame([], $this->links->reconcile($registry->all()), 'Nothing missing, nothing done.');
        self::assertSame(['docker network ls', 'docker network inspect', 'docker container inspect', 'docker container inspect', 'docker network inspect'], \array_slice($this->runner->keys(), $commands), 'The same few calls, whatever the number of sites (the last: the server\'s network, for its logins).');
        self::assertSame(2, $this->reloads, 'PostgreSQL read its rules again for each change only: jane\'s login, then the server\'s (demo has its own PostgreSQL: nothing changed).');
    }

    public function testSitesWithoutAddressesOrRemovedAreLeftAlone(): void
    {
        $this->links->ensure(new SiteName('jane'), 'jane', true);
        $registry = new SiteRegistry($this->connection, $this->schema);
        $registry->setStatus(new SiteName('jane'), Site::REMOVED);
        unset($this->networks['quire-link-jane']);

        self::assertSame(['the server\'s logins to PostgreSQL from its network written again', 'sites\' logins to PostgreSQL from their links written again'], $this->links->reconcile($registry->all()), 'jane is removed (its login goes); demo has no addresses yet (its add makes its link).');
        self::assertStringNotContainsString('site_jane', (string) file_get_contents($this->access.'/sites.conf'));
        self::assertArrayNotHasKey('quire-link-demo', $this->networks);
    }

    public function testALinkConnectedMeanwhileIsDone(): void
    {
        $this->runner->script['docker network connect'] = static fn (array $command): ProcessResult => ScriptedRunner::failed(array_values(array_map(static fn (mixed $part): string => \is_scalar($part) ? (string) $part : '', $command)), 'Error response from daemon: endpoint with name quire-server-front-1 already exists in network quire-link-jane');

        $this->links->ensure(new SiteName('jane'), 'jane', true);

        self::assertArrayHasKey('quire-link-jane', $this->networks, 'An operation on the site and the agent\'s round, at once: no failure.');
    }

    /**
     * @return array{ip: string, aliases: list<string>}|null
     */
    private function attachment(string $container, string $network): ?array
    {
        return $this->containers[$container][$network] ?? null;
    }

    /**
     * @param array<array-key, mixed> $command
     */
    private function option(array $command, string $name): string
    {
        $index = array_search($name, $command, true);

        $value = false === $index ? null : $command[(int) $index + 1] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }
}
