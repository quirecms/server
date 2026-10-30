<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Process;

use PHPUnit\Framework\TestCase;
use Quire\Server\Process\SymfonyProcessRunner;

/**
 * What a program the agent runs sees of the agent's environment, with real programs: above all, none
 * of the server's secrets. Compose, for one, fills in a site's "${NAME}" from its environment, ahead of
 * the site's own env file, so a site's Compose file could otherwise copy them into a container.
 */
final class SymfonyProcessRunnerTest extends TestCase
{
    /** The agent's own settings, in each of the places PHP keeps an environment */
    private const array SECRETS = [
        'QUIRE_SERVER_POSTGRES_ADMIN_URL' => 'postgresql://postgres:superuser@quire-database:5432/postgres',
        'QUIRE_SERVER_BACKUP_PASSWORD' => 'the backups\' password',
        'APP_SECRET' => 'the agent\'s secret',
    ];

    #[\Override]
    protected function setUp(): void
    {
        putenv('QUIRE_SERVER_POSTGRES_ADMIN_URL='.self::SECRETS['QUIRE_SERVER_POSTGRES_ADMIN_URL']);
        $_ENV['QUIRE_SERVER_BACKUP_PASSWORD'] = self::SECRETS['QUIRE_SERVER_BACKUP_PASSWORD'];
        $_SERVER['APP_SECRET'] = self::SECRETS['APP_SECRET'];
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('QUIRE_SERVER_POSTGRES_ADMIN_URL');
        unset($_ENV['QUIRE_SERVER_BACKUP_PASSWORD'], $_SERVER['APP_SECRET']);
    }

    public function testAProgramGetsNoneOfTheAgentsSettings(): void
    {
        $env = $this->environment();

        foreach (array_keys(self::SECRETS) as $name) {
            self::assertArrayNotHasKey($name, $env);
        }

        self::assertStringNotContainsString('superuser', implode("\n", $env));
        self::assertSame(['HOME', 'PATH'], array_values(array_intersect(['HOME', 'PATH'], array_keys($env))), 'Enough to run.');
    }

    public function testAProgramGetsWhatItIsGiven(): void
    {
        $env = $this->environment(['DOCKER_HOST' => 'tcp://docker-proxy:2375', 'APP_SECRET' => 'the site\'s secret', 'HOME' => false]);

        self::assertSame('tcp://docker-proxy:2375', $env['DOCKER_HOST'] ?? null);
        self::assertSame('the site\'s secret', $env['APP_SECRET'] ?? null);
        self::assertArrayNotHasKey('HOME', $env, 'A basic can be taken away too.');
    }

    /**
     * @param array<string, string|false> $given
     *
     * @return array<string, string> What `env` printed
     */
    private function environment(array $given = []): array
    {
        $result = new SymfonyProcessRunner()->run(['env'], $given, timeout: 10)->orFail('env');
        $env = [];

        foreach (explode("\n", trim($result->output)) as $line) {
            [$name, $value] = explode('=', $line, 2) + [1 => ''];
            $env[$name] = $value;
        }

        return $env;
    }
}
