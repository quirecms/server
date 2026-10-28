<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\TestCase;
use Quire\Server\Docker\RuntimeImageNotTrusted;
use Quire\Server\Docker\RuntimeImages;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Quire's runtime images, checked before a site builds on them (cosign scripted): built on by the
 * digest that was checked, or not at all.
 */
final class RuntimeImagesTest extends TestCase
{
    private const string BUILDER = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string RUNTIME = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string DOCKERFILE = "#syntax=docker/dockerfile:1\nARG RUNTIME_BUILDER=ghcr.io/quirecms/runtime:8.5-builder\nARG RUNTIME=ghcr.io/quirecms/runtime:8.5\n\nFROM \${RUNTIME_BUILDER} AS frankenphp_base\n";

    private string $site;

    /** @var array<string, string> What cosign says it checked, by image */
    private array $signed = ['ghcr.io/quirecms/runtime:8.5-builder' => self::BUILDER, 'ghcr.io/quirecms/runtime:8.5' => self::RUNTIME];

    /** @var list<string> */
    private array $said = [];
    private ScriptedRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        $this->site = sys_get_temp_dir().'/quire-runtime-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->site.'/Dockerfile', self::DOCKERFILE);
        $this->runner = new ScriptedRunner([
            'cosign verify' => function (array $command): ProcessResult {
                $image = (string) end($command);
                $digest = $this->signed[$image] ?? $this->signed[(string) strstr($image, '@', true)] ?? null;

                return null === $digest
                    ? ScriptedRunner::failed($command, 'Error: no matching signatures: none of the expected identities matched')
                    : ScriptedRunner::ok($command, json_encode([['critical' => ['image' => ['docker-manifest-digest' => $digest]]], ['critical' => ['image' => ['docker-manifest-digest' => $digest]]]])."\n");
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->site);
    }

    public function testItBuildsOnTheDigestsThatWereChecked(): void
    {
        self::assertSame([
            'RUNTIME_BUILDER' => 'ghcr.io/quirecms/runtime:8.5-builder@'.self::BUILDER,
            'RUNTIME' => 'ghcr.io/quirecms/runtime:8.5@'.self::RUNTIME,
        ], $this->pinned());

        foreach ($this->runner->runs as $run) {
            self::assertContains('https://github.com/quirecms/framework/.github/workflows/runtime.yaml@refs/heads/main', $run['command'], 'Signed by the framework\'s runtime workflow, from main');
            self::assertContains('https://token.actions.githubusercontent.com', $run['command']);
        }

        self::assertCount(2, $this->said);
    }

    public function testARuntimeThatDoesNotCheckOutStopsTheBuild(): void
    {
        unset($this->signed['ghcr.io/quirecms/runtime:8.5']);
        $this->expectException(RuntimeImageNotTrusted::class);
        $this->expectExceptionMessageIsOrContains('ghcr.io/quirecms/runtime:8.5 didn\'t check out');
        $this->pinned();
    }

    public function testAPinnedDigestMustBeTheOneChecked(): void
    {
        $this->dockerfile("ARG RUNTIME=ghcr.io/quirecms/runtime:8.5@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc\nFROM \${RUNTIME}\n");
        $this->expectExceptionMessageIsOrContains('isn\'t the image that was checked');
        $this->pinned();
    }

    public function testAPinnedDigestThatWasCheckedIsKept(): void
    {
        $this->dockerfile('ARG RUNTIME=ghcr.io/quirecms/runtime:8.5@'.self::RUNTIME."\nFROM \${RUNTIME}\n");
        self::assertSame(['RUNTIME' => 'ghcr.io/quirecms/runtime:8.5@'.self::RUNTIME], $this->pinned());
    }

    public function testAnAnswerAboutSeveralImagesIsRefused(): void
    {
        // cosign's answer names two images
        $several = static fn (array $command): ProcessResult => new ProcessResult([], 0, (string) json_encode([['critical' => ['image' => ['docker-manifest-digest' => self::BUILDER]]], ['critical' => ['image' => ['docker-manifest-digest' => self::RUNTIME]]]]), '');
        $this->runner->script['cosign verify'] = $several;
        $this->expectExceptionMessageIsOrContains('didn\'t say which image it checked');
        $this->pinned();
    }

    public function testAnImageThatOnlyLooksLikeTheRuntimeIsRefused(): void
    {
        $this->dockerfile("ARG RUNTIME=ghcr.io/quirecms/runtime:8.5;evil\nFROM \${RUNTIME}\n");
        $this->expectExceptionMessageIsOrContains('isn\'t an image of Quire\'s runtime');
        $this->pinned();
    }

    public function testAnotherBaseImageIsTheSitesChoice(): void
    {
        $this->dockerfile("ARG RUNTIME_BUILDER=registry.example.com/mine:1\nARG RUNTIME=ghcr.io/quirecms/runtime-fork:8.5\nFROM \${RUNTIME_BUILDER}\n");

        self::assertSame([], $this->pinned());
        self::assertSame([], $this->runner->runs);
        self::assertCount(2, $this->said, 'Said in the operation\'s log.');
    }

    public function testSitesFromOlderSkeletonsBuildAsBefore(): void
    {
        $this->dockerfile("FROM dunglas/frankenphp:1-php8.5 AS frankenphp_upstream\nARG RUNTIME=ghcr.io/quirecms/runtime:8.5\n");

        self::assertSame([], $this->pinned(), 'Only arguments before the first FROM name what FROM builds on.');
        $this->dockerfile("from dunglas/frankenphp:1-php8.5\narg RUNTIME=ghcr.io/quirecms/runtime:8.5\n");
        self::assertSame([], $this->pinned(), 'Instructions are case-insensitive.');
        self::assertSame([], $this->runner->runs);
    }

    public function testNoDockerfileNothingToCheck(): void
    {
        new Filesystem()->remove($this->site.'/Dockerfile');
        self::assertSame([], $this->pinned());
    }

    /**
     * @return array<string, string>
     */
    private function pinned(): array
    {
        return new RuntimeImages($this->runner)->pinned($this->site, function (string $line): void {
            $this->said[] = $line;
        });
    }

    private function dockerfile(string $content): void
    {
        file_put_contents($this->site.'/Dockerfile', $content);
    }

    public function testAnImageCheckedInTheLastMinutesIsntCheckedAgain(): void
    {
        $now = 1000;
        $runtime = new RuntimeImages($this->runner, static function () use (&$now): int {
            return $now;
        });
        $runtime->pinned($this->site, static function (): void {});
        $runtime->pinned($this->site, static function (): void {});
        self::assertCount(2, $this->runner->runs, 'Each image checked once.');

        $now += 601;
        $this->signed['ghcr.io/quirecms/runtime:8.5'] = 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
        self::assertSame('ghcr.io/quirecms/runtime:8.5@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd', $runtime->pinned($this->site, static function (): void {})['RUNTIME'] ?? null, 'Then checked again: a newer build.');
    }
}
