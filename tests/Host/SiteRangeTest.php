<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Host;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Network\SiteNetworkRange;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * bin/site-range.sh, as "quire-server up" and "update" run it: the machine's routes against the sites'
 * range, with what "ip" and "docker" answer on a host (an Ubuntu droplet's, read 2026-11-03).
 */
final class SiteRangeTest extends TestCase
{
    private const string ROUTES = <<<'ROUTES'
        default via 138.68.96.1 dev eth0 proto static
        10.19.0.0/16 dev eth0 proto kernel scope link src 10.19.0.6
        10.135.0.0/16 dev eth1 proto kernel scope link src 10.135.0.3
        172.17.0.0/16 dev docker0 proto kernel scope link src 172.17.0.1 linkdown
        172.19.0.0/16 dev br-ef35eadd8c23 proto kernel scope link src 172.19.0.1
        10.72.0.0/27 dev br-0a1b2c3d4e5f proto kernel scope link src 10.72.0.1
        10.72.0.32/27 dev quire-named proto kernel scope link src 10.72.0.33
        10.80.0.0/16 proto static table 51820
        	nexthop via 10.19.0.1 dev eth0 weight 1
        ROUTES;

    private const string NETWORKS = <<<'NETWORKS'
        15d7d092c50e3c1e593f3e6983a3daf18d735935a7542b3eb2dd61cf28d859f9 docker0
        ef35eadd8c23c0815b8b4e06fb113bdf7c97487fc1505b8ce74ba14ed064ba0c
        0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b
        9f8e7d6c5b4a39281706f5e4d3c2b1a09f8e7d6c5b4a39281706f5e4d3c2b1a0 quire-named
        NETWORKS;

    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-site-range-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->root.'/bin/routes', self::ROUTES."\n");
        $filesystem->dumpFile($this->root.'/bin/networks', self::NETWORKS."\n");
        $filesystem->dumpFile($this->root.'/bin/ip', "#!/bin/sh\n[ \"\$*\" = '-4 route show table all type unicast' ] && cat \"\$(dirname \"\$0\")/routes\"\n");
        $filesystem->dumpFile($this->root.'/bin/docker', "#!/bin/sh\ncase \"\$*\" in\n\t'network ls '*) cut -d ' ' -f 1 \"\$(dirname \"\$0\")/networks\" ;;\n\t'network inspect '*) cat \"\$(dirname \"\$0\")/networks\" ;;\n\t*) exit 1 ;;\nesac\n");
        $filesystem->chmod([$this->root.'/bin/ip', $this->root.'/bin/docker'], 0o755);
        $filesystem->dumpFile($this->root.'/server/server.env', "QUIRE_SERVER_APP_SECRET=x\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testDockersOwnNetworksInTheRangeAreNoOverlap(): void
    {
        self::assertSame('', $this->shell('site_range_warn "$ROOT/server"'), 'Sites\' networks are in the range by design, a bridge with a name of its own too.');
    }

    public function testEveryOtherRouteInTheRangeIsSaidWithWhatToDo(): void
    {
        self::assertSame("10.135.0.0/16 (eth1)\n10.19.0.0/16 (eth0)\n10.80.0.0/16 (?)\n", $this->shell('site_range_overlaps 10.0.0.0/8'), 'The machine\'s own networks, another table\'s route too; not the default route.');

        new Filesystem()->dumpFile($this->root.'/server/server.env', "QUIRE_SERVER_SITE_NETWORKS='10.128.0.0/9'\n");
        // A VPN on part of a range set in server.env
        new Filesystem()->appendToFile($this->root.'/bin/routes', "10.135.4.0/24 dev wg0 scope link\n");

        self::assertSame(
            "This machine routes part of the sites' addresses (10.128.0.0/9, QUIRE_SERVER_SITE_NETWORKS) elsewhere:\n  10.135.0.0/16 (eth1)\n  10.135.4.0/24 (wg0)\nThat network and sites with addresses there get in each other's way. Move it (a VPN, a cloud network) off the range: sites keep the addresses they have, so a new QUIRE_SERVER_SITE_NETWORKS would only help sites added after it.\n",
            $this->shell('site_range_warn "$ROOT/server" 2>&1'),
        );
    }

    public function testTheFreeRangeNamedIsTheDefaultUnlessARouteHasIt(): void
    {
        self::assertSame("10.72.0.0/16\n", $this->shell('site_range_free'), 'Sites\' own networks don\'t take it.');

        new Filesystem()->appendToFile($this->root.'/bin/routes', "10.72.128.0/17 dev wg0 scope link\n");
        self::assertSame("10.73.0.0/16\n", $this->shell('site_range_free'));

        new Filesystem()->appendToFile($this->root.'/bin/routes', "10.64.0.0/10 dev tun0 scope link\n");
        self::assertSame('', $this->shell('site_range_free'), 'None: the installer asks for another range.');
    }

    public function testARangeThatIsntOneOverlapsNothing(): void
    {
        self::assertSame('', $this->shell('site_range_overlaps "10.72.0.0/16; reboot"'), 'site_range_problem says why, and the agent refuses it.');
    }

    /**
     * The installer refuses what the agent wouldn't start with, before anything runs: the same rules,
     * and the reason with the range.
     */
    #[DataProvider('ranges')]
    public function testARangeIsRefusedAsTheAgentRefusesIt(string $range): void
    {
        try {
            new SiteNetworkRange($range);
            $agent = true;
        } catch (\InvalidArgumentException) {
            $agent = false;
        }

        $problem = $this->shell('site_range_problem "$RANGE"', ['RANGE' => $range]);

        self::assertSame($agent, '' === $problem, $range.': '.$problem);
        self::assertSame($agent ? 0 : 1, substr_count($problem, "\n"), 'One line, when there is one.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ranges(): iterable
    {
        foreach ([
            '10.72.0.0/16', '10.0.0.0/8', '10.72.0.0/22', '172.16.0.0/12', '172.31.252.0/22', '192.168.0.0/16', '192.168.4.0/22',
            '10.72.0.0/23', '10.0.0.0/7', '172.15.0.0/16', '172.32.0.0/16', '192.169.0.0/16', '192.168.0.0/15', '8.8.0.0/16', '0.0.0.0/8',
            '10.72.1.0/16', '10.72.0.1/22', '10.256.0.0/16', '010.72.0.0/16', '10.72.00.0/16', '10.72.0.0/016', '10.72.0.0/33', '10.72.0.0',
            '10.72.0.0/', '1.2.3/8', '10.72.0.0/16; reboot', ' 10.72.0.0/16', '10.72.0.0/16 ', '', '10.72.0.0/16/8', '1e1.0.0.0/8',
        ] as $range) {
            yield '"'.$range.'"' => [$range];
        }
    }

    /**
     * @param array<string, string> $environment
     */
    private function shell(string $script, array $environment = []): string
    {
        $process = new Process(['sh', '-c', '. "$SCRIPT"; '.$script], null, ['SCRIPT' => \dirname(__DIR__, 2).'/bin/site-range.sh', 'ROOT' => $this->root, 'PATH' => $this->root.'/bin:/usr/bin:/bin', ...$environment]);
        $process->mustRun();

        return $process->getOutput();
    }
}
