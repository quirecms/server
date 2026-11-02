<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\TestCase;
use Quire\Server\Docker\Images;
use Quire\Server\Docker\PolicyContext;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Tests\GateFixture;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

final class ImagesTest extends TestCase
{
    private const string ID = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-images-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->root.'/sites/jane');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testASitesImagesAreNamedOnlyInItsOwnNamespace(): void
    {
        $runner = new ScriptedRunner(['docker image' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command)]);
        $images = new Images($runner, GateFixture::gate($runner, $this->root));
        $images->tag($this->project(), self::ID, 'quire-sites/jane/php-prod:before-update');

        // On the trusted socket: an image a site ran before its build has no name the gate could check.
        self::assertSame(GateFixture::TRUSTED, $runner->runs[0]['env']['DOCKER_HOST'] ?? null);

        foreach (['quire-sites/jane-blog/php-prod', 'quire-sites/janet/php-prod', 'quire-server-agent:latest', 'postgres:18'] as $name) {
            try {
                $images->tag($this->project(), self::ID, $name);
                self::fail($name.' was named.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $images->ofContainer($this->project(), 'quire-server-database-1');
    }

    private function project(): SiteProject
    {
        return new SiteProject($this->root.'/sites/jane', $this->root.'/sites/jane/prod.env', new PolicyContext('jane', $this->root.'/sites/jane', []));
    }
}
