<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Operation;

use Quire\Server\Docker\Images;
use Quire\Server\Operation\SiteOverrides;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Sites as an earlier release left them, brought up to date at the agent's start, with Docker scripted:
 * their override written as this release does, and the image they run named as this release names it.
 */
final class SiteOverridesTest extends RegistryTestCase
{
    private const string RUNNING = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private string $root;
    private ServerPaths $paths;
    private SiteRegistry $registry;
    private ScriptedRunner $runner;

    /** @var array<string, string> Image name => ID */
    private array $tags = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-overrides-'.bin2hex(random_bytes(6));
        $this->paths = new ServerPaths($this->root);
        $this->registry = new SiteRegistry($this->connection, $this->schema);
        $this->runner = new ScriptedRunner([
            'docker image inspect' => fn (array $command): ProcessResult => isset($this->tags[$command[5]]) ? ScriptedRunner::ok($command, $this->tags[$command[5]]."\n") : ScriptedRunner::failed($command, 'No such image'),
            'docker container inspect' => static fn (array $command): ProcessResult => 'demo_x-php-1' === $command[5] || 'jane-php-1' === $command[5] ? ScriptedRunner::ok($command, self::RUNNING."\n") : ScriptedRunner::failed($command, 'No such container'),
            'docker image tag' => function (array $command): ProcessResult {
                $this->tags[$command[4]] = $command[3];

                return ScriptedRunner::ok($command);
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testSitesOfAnEarlierReleaseAreBroughtUpToDate(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $this->site('demo', 'demo_x', Site::OWN_DATABASE);
        $this->registry->add(new SiteName('gone'), [new Domain('gone.example')], Site::SHARED_DATABASE, 'gone');
        $this->registry->setStatus(new SiteName('gone'), Site::REMOVED);

        self::assertSame([], $this->subject()->refresh());

        $jane = (string) file_get_contents($this->paths->site(new SiteName('jane')).'/'.SiteFiles::OVERRIDE);
        self::assertStringContainsString('image: quire-sites/jane/php-prod', $jane);
        self::assertStringContainsString('jane.example', $jane, 'Its domains, from the registry.');
        self::assertStringContainsString('image: quire-sites/demo_x/php-prod', (string) file_get_contents($this->paths->site(new SiteName('demo')).'/'.SiteFiles::OVERRIDE), 'An adopted site\'s own project.');
        ksort($this->tags);
        self::assertSame(['quire-sites/demo_x/php-prod' => self::RUNNING, 'quire-sites/jane/php-prod' => self::RUNNING], $this->tags, 'The image each runs, named anew: its next update can be undone.');
        self::assertFileDoesNotExist($this->paths->site(new SiteName('gone')).'/'.SiteFiles::OVERRIDE);
    }

    public function testANameItHasAlreadyIsKept(): void
    {
        $this->site('jane', 'jane', Site::SHARED_DATABASE);
        $this->tags['quire-sites/jane/php-prod'] = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

        $this->subject()->refresh();

        self::assertNotContains('docker image tag', $this->runner->keys());
    }

    private function site(string $name, string $project, string $mode): void
    {
        $this->registry->add(new SiteName($name), [new Domain($name.'.example')], $mode, $project);
        $this->registry->setStatus(new SiteName($name), Site::RUNNING);
        new Filesystem()->dumpFile($this->paths->site(new SiteName($name)).'/'.SiteFiles::OVERRIDE, "services:\n  php:\n    cap_drop: [ALL]\n");
    }

    private function subject(): SiteOverrides
    {
        return new SiteOverrides($this->registry, new SiteFiles(), $this->paths, new Images($this->runner, 'tcp://docker-proxy:2375'), new SiteLock($this->connection));
    }
}
