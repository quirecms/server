<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerUpdate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Process\SymfonyProcessRunner;
use Quire\Server\ServerUpdate\ServerUpdater;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Which settings the server's Docker gate reads, as the real "docker compose config" makes of
 * compose.yaml: the server's own, by their path on the host, however the release is unpacked (an
 * updater of 0.4.0 unpacked it where Docker doesn't see), unless the updater names the release's.
 */
final class GateSettingsMountTest extends TestCase
{
    private const string SERVER = __DIR__.'/../..';

    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quire-gate-mount-'.bin2hex(random_bytes(6));
        // The release's files, unpacked elsewhere (as an updater does), with a server.env
        new Filesystem()->copy(self::SERVER.'/compose.yaml', $this->directory.'/compose.yaml');
        file_put_contents($this->directory.'/server.env', "QUIRE_SERVER_APP_SECRET=x\nQUIRE_SERVER_DATABASE_PASSWORD=x\nQUIRE_SERVER_POSTGRES_PASSWORD=x\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function settings(): iterable
    {
        yield 'the server, as it runs' => [[], '/srv/quire/server/gate/yorvogate.yaml'];
        yield 'a server installed elsewhere' => [['QUIRE_SERVER_ROOT=/opt/quire'], '/opt/quire/server/gate/yorvogate.yaml'];
        yield 'an update\'s check' => [[ServerUpdater::GATE_CONFIG.'=/srv/quire/server/.update-1a2b/files/gate/yorvogate.yaml'], '/srv/quire/server/.update-1a2b/files/gate/yorvogate.yaml'];
    }

    /**
     * @param list<string> $env Lines of server.env
     */
    #[DataProvider('settings')]
    public function testTheGateReadsTheSettingsItShould(array $env, string $source): void
    {
        file_put_contents($this->directory.'/server.env', implode("\n", $env)."\n", \FILE_APPEND);
        $config = new SymfonyProcessRunner()->run(['docker', 'compose', '--project-directory', $this->directory, '--env-file', $this->directory.'/server.env', '-f', $this->directory.'/compose.yaml', 'config', '--format', 'json'], ['QUIRE_SERVER_ROOT' => false, ServerUpdater::GATE_CONFIG => false])->orFail('Reading compose.yaml');
        /** @var array{services: array{gate: array{volumes: list<array{source: string, target: string}>}}} $parsed */
        $parsed = json_decode($config->output, true, 64, \JSON_THROW_ON_ERROR);
        $mounts = array_column($parsed['services']['gate']['volumes'], 'source', 'target');

        self::assertSame($source, $mounts['/etc/yorvogate/yorvogate.yaml'] ?? null, 'Never relative to where the release was unpacked.');
    }
}
