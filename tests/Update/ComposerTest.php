<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Update\Composer;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What a site's composer.json may ask of Composer before it runs: fetching only over HTTPS, with no
 * request options of its own. Where Composer runs, in a container of its own, is ContainerRunnerTest's.
 */
final class ComposerTest extends TestCase
{
    private string $directory;
    private ScriptedRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quire-composer-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->directory.'/composer.lock', '{}');
        $this->runner = new ScriptedRunner([
            'composer audit' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command, '{"advisories": []}'),
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unsafe(): iterable
    {
        yield 'plain HTTP allowed' => [['config' => ['secure-http' => false]], '"secure-http": false'];
        yield 'no TLS' => [['config' => ['disable-tls' => true]], '"disable-tls": true'];
        yield 'a repository over HTTP' => [['repositories' => [['type' => 'composer', 'url' => 'http://docker-proxy:2375']]], '"http://docker-proxy:2375" (plain HTTP)'];
        yield 'HTTP, spelled otherwise' => [['repositories' => ['proxy' => ['type' => 'vcs', 'url' => 'HTTP://quire-database:5432/x']]], '"HTTP://quire-database:5432/x"'];
        yield 'a package from HTTP' => [['repositories' => [['type' => 'package', 'package' => ['name' => 'a/b', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'http://docker-proxy:2375/containers/json']]]]], '"http://docker-proxy:2375/containers/json"'];
        yield 'request options' => [['repositories' => [['type' => 'composer', 'url' => 'https://repo.example.com', 'options' => ['http' => ['method' => 'POST', 'content' => '{}']]]]], 'a repository\'s "options"'];
    }

    /**
     * @param array<string, mixed> $json
     */
    #[DataProvider('unsafe')]
    public function testFetchingOtherThanOverHttpsIsRefused(array $json, string $expected): void
    {
        file_put_contents($this->directory.'/composer.json', json_encode($json, \JSON_THROW_ON_ERROR));

        try {
            new Composer($this->runner)->audit($this->directory);
            self::fail('Not refused.');
        } catch (\DomainException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
            self::assertSame([], $this->runner->runs, 'Before Composer runs.');
        }
    }

    public function testRepositoriesOverHttpsPass(): void
    {
        file_put_contents($this->directory.'/composer.json', json_encode([
            'repositories' => [['type' => 'composer', 'url' => 'https://repo.packagist.com/acme/'], ['type' => 'vcs', 'url' => 'git@github.com:acme/private.git']],
            'config' => ['secure-http' => true, 'sort-packages' => true],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], new Composer($this->runner)->audit($this->directory));
    }
}
