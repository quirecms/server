<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Process\ProcessRunner;

/**
 * The network of what runs a site's untrusted input outside the agent: Composer in its sandbox
 * (ContainerRunner) and sites' builders (Builders). Docker's default kind, with a way out to the
 * internet, and nothing else on it: none of the server's networks (its PostgreSQL), no site, and not
 * Docker's default bridge (where the host's other containers may be).
 */
final readonly class SandboxNetwork
{
    public const string NAME = 'quire-sandbox';
    public const string LABEL = 'com.quirecms.server.sandbox';

    public function __construct(
        private ProcessRunner $runner,
        private string $dockerHost,
    ) {
    }

    public function ensure(): string
    {
        $env = ['DOCKER_HOST' => $this->dockerHost];

        if ($this->runner->run(['docker', 'network', 'inspect', self::NAME], $env, timeout: 60)->successful()) {
            return self::NAME;
        }

        $created = $this->runner->run(['docker', 'network', 'create', '--label', self::LABEL.'=1', self::NAME], $env, timeout: 60);

        // Another run may have just created it.
        if (!$created->successful() && !str_contains($created->errorOutput, 'already exists')) {
            $created->orFail('Creating the network for programs in containers');
        }

        return self::NAME;
    }
}
