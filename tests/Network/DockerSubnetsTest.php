<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Network;

use PHPUnit\Framework\TestCase;
use Quire\Server\Network\DockerSubnets;
use Quire\Server\Tests\ScriptedRunner;

final class DockerSubnetsTest extends TestCase
{
    public function testEveryNetworksIpv4SubnetsByName(): void
    {
        $runner = new ScriptedRunner([
            'docker network ls' => static fn (array $command): \Quire\Server\Process\ProcessResult => ScriptedRunner::ok($command, "aaa\nbbb\nccc\nddd\n"),
            'docker network inspect' => static fn (array $command): \Quire\Server\Process\ProcessResult => ScriptedRunner::ok($command, "bridge 172.17.0.0/16\njane_default 172.18.0.0/16 fd00:1::/64\nhost\nvpn 10.72.4.0/24\n"),
        ]);

        $subnets = new DockerSubnets($runner, 'unix:///trusted.sock')->all();

        self::assertSame(['bridge' => ['172.17.0.0/16'], 'jane_default' => ['172.18.0.0/16'], 'host' => [], 'vpn' => ['10.72.4.0/24']], array_map(static fn (array $list): array => array_map(strval(...), $list), $subnets), 'IPv6 left out; a network without subnets has none.');
        self::assertSame(['DOCKER_HOST' => 'unix:///trusted.sock'], $runner->runs[0]['env'], 'On the agent\'s own socket.');
        self::assertSame(['aaa', 'bbb', 'ccc', 'ddd'], \array_slice($runner->runs[1]['command'], -4), 'Inspected all at once.');
    }

    public function testNoNetworksNoSubnets(): void
    {
        $runner = new ScriptedRunner(['docker network ls' => static fn (array $command): \Quire\Server\Process\ProcessResult => ScriptedRunner::ok($command)]);

        self::assertSame([], new DockerSubnets($runner, 'unix:///trusted.sock')->all());
        self::assertSame(['docker network ls'], $runner->keys(), 'Nothing to inspect.');
    }
}
