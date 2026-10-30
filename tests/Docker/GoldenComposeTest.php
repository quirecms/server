<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use Quire\Server\Docker\Compose;
use Quire\Server\Docker\ComposeFiles;
use Quire\Server\Docker\ComposePolicy;
use Quire\Server\Docker\PolicyContext;
use Quire\Server\Docker\PolicyViolation;
use Quire\Server\Docker\RuntimeImages;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Process\SymfonyProcessRunner;
use Quire\Server\Registry\RuntimeBuilds;
use Quire\Server\Registry\RuntimeFloors;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The policy against what Compose itself makes of a site's files: the real "docker compose config"
 * (the agent image's own Compose, which the development image is built on), on the files in golden/,
 * each a site as the server runs it or one that reaches for the host or another site. The policy
 * reads Compose's output, so a new Compose that writes a setting another way is caught here, before
 * a release, and not on a server.
 *
 * Each case is a folder: its compose.yaml and prod.env, and in expect.txt "pass", the words of the
 * refusal, or "error" (Compose refuses it itself).
 */
final class GoldenComposeTest extends RegistryTestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-golden-'.bin2hex(random_bytes(6));
        // Another site beside it, which its files may name
        new Filesystem()->dumpFile($this->root.'/sites/other/compose.yaml', "services:\n  php:\n    image: alpine\n    privileged: true\n");
        new Filesystem()->dumpFile($this->root.'/sites/other/prod.env', "APP_SECRET=the-other-sites\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        $folders = glob(__DIR__.'/golden/*', \GLOB_ONLYDIR);

        foreach (false === $folders ? [] : $folders as $folder) {
            yield basename($folder) => [$folder];
        }
    }

    #[DataProvider('cases')]
    public function testWhatComposeMakesOfItIsJudgedRight(string $folder): void
    {
        $site = $this->root.'/sites/jane';
        new Filesystem()->mirror($folder, $site);
        $expected = trim((string) file_get_contents($site.'/expect.txt'));
        $project = new SiteProject($site, $site.'/prod.env', new PolicyContext('jane', $site, [
            'quire-edge' => ['php' => ['jane.example']],
            'quire-data' => ['php' => []],
        ]));

        try {
            $this->compose()->check($project);
            $outcome = 'pass';
        } catch (PolicyViolation $e) {
            $outcome = $e->getMessage();
        } catch (\RuntimeException $e) {
            $outcome = 'error: '.$e->getMessage();
        }

        match ($expected) {
            'pass' => self::assertSame('pass', $outcome),
            'error' => self::assertStringStartsWith('error: ', $outcome, 'Compose refuses it itself.'),
            default => self::assertStringContainsString($expected, $outcome),
        };
    }

    public function testThereAreCases(): void
    {
        self::assertGreaterThan(20, iterator_count(self::cases()));
    }

    private function compose(): Compose
    {
        $runner = new SymfonyProcessRunner();

        // Nothing here reaches Docker: "config" reads files only.
        return new Compose($runner, new ComposePolicy(), new ComposeFiles(), new RuntimeImages($runner, new RuntimeFloors($this->connection, $this->schema)), new RuntimeBuilds($this->connection, $this->schema), 'tcp://127.0.0.1:9');
    }
}
