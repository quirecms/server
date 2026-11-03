<?php

declare(strict_types=1);

namespace Quire\Server\Tests;

use Quire\Server\Docker\Builders;
use Quire\Server\Docker\Gate;
use Quire\Server\Docker\SandboxNetwork;
use Quire\Server\Process\ProcessRunner;

/**
 * The Docker gate as tests have it: its folders under a test's root, and the sockets of the sites a
 * test names open (as the gate opens them for their scope files), so nothing waits for a gate.
 */
final class GateFixture
{
    public const string TRUSTED = 'unix:///run/quire-gate/trusted/trusted.sock';

    public static function gate(ProcessRunner $runner, string $root, string ...$projects): Gate
    {
        self::open($root, ...$projects);

        return new Gate($runner, self::TRUSTED, $root.'/gate/scopes', $root.'/gate/sites', $root.'/gate/docker', reloadSeconds: 0.0);
    }

    /**
     * Builders, on a host without AppArmor unless a profile is given.
     */
    public static function builders(ProcessRunner $runner, string $root, string $apparmor = 'none'): Builders
    {
        if (!is_dir($root.'/host')) {
            mkdir($root.'/host', 0o700, true);
        }

        file_put_contents($root.'/host/builder-apparmor', $apparmor."\n");

        return new Builders($runner, new SandboxNetwork($runner, self::TRUSTED), self::TRUSTED, $root.'/host/builder-apparmor');
    }

    /**
     * The sites' sockets, as the gate opens them.
     */
    public static function open(string $root, string ...$projects): void
    {
        if (!is_dir($root.'/gate/sites')) {
            mkdir($root.'/gate/sites', 0o700, true);
        }

        foreach ($projects as $project) {
            $path = $root.'/gate/sites/'.$project.'.sock';

            if ('socket' !== @filetype($path)) {
                $server = stream_socket_server('unix://'.$path);

                if (false === $server) {
                    throw new \RuntimeException('Couldn\'t open '.$path);
                }

                // The file stays a socket once closed: that's all the agent looks for.
                fclose($server);
            }
        }
    }

    /**
     * Whether an environment is a site's: its own Docker configuration, whose context is its socket.
     *
     * @param array<string, string|false> $env
     */
    public static function isSite(array $env, string $root, string $project): bool
    {
        return ($env['DOCKER_CONFIG'] ?? null) === $root.'/gate/docker/'.$project && false === ($env['DOCKER_HOST'] ?? null);
    }
}
