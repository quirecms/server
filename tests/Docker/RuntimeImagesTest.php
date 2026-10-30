<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use Quire\Server\Docker\RuntimeImageNotTrusted;
use Quire\Server\Docker\RuntimeImages;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\RuntimeFloors;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Quire's runtime images, checked before a site builds on them (cosign and the registry scripted, the
 * floor on the registry's real tables): built on by the digest that was checked, or not at all.
 */
final class RuntimeImagesTest extends RegistryTestCase
{
    private const string BUILDER = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string RUNTIME = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string DOCKERFILE = "#syntax=docker/dockerfile:1\nARG RUNTIME_BUILDER=ghcr.io/quirecms/runtime:8.5-builder\nARG RUNTIME=ghcr.io/quirecms/runtime:8.5\n\nFROM \${RUNTIME_BUILDER} AS frankenphp_base\n";

    private string $site;

    /** @var array<string, string> What cosign says it checked, by image */
    private array $signed = ['ghcr.io/quirecms/runtime:8.5-builder' => self::BUILDER, 'ghcr.io/quirecms/runtime:8.5' => self::RUNTIME];

    /** @var array<string, array{string, string}> What each digest's image is: its title, and when it was built */
    private array $images = [
        self::BUILDER => ['Quire runtime (builder)', '2026-10-26T02:50:00Z'],
        self::RUNTIME => ['Quire runtime (runtime)', '2026-10-26T02:55:00Z'],
    ];

    private int $now = 1_793_000_000;

    /** @var list<string> */
    private array $said = [];
    private ScriptedRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
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
            // An index: each platform's configuration
            'docker buildx imagetools' => function (array $command): ProcessResult {
                [$title, $created] = $this->images[substr((string) strrchr((string) end($command), '@'), 1)] ?? ['', ''];
                $config = ['created' => $created, 'config' => ['Labels' => ['org.opencontainers.image.title' => $title]]];

                return ScriptedRunner::ok($command, (string) json_encode(['linux/amd64' => $config, 'linux/arm64' => $config]));
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->site);
        parent::tearDown();
    }

    public function testItBuildsOnTheDigestsThatWereChecked(): void
    {
        self::assertSame([
            'RUNTIME_BUILDER' => 'ghcr.io/quirecms/runtime:8.5-builder@'.self::BUILDER,
            'RUNTIME' => 'ghcr.io/quirecms/runtime:8.5@'.self::RUNTIME,
        ], $this->pinned());

        foreach (array_filter($this->runner->runs, static fn (array $run): bool => 'cosign' === $run['command'][0]) as $run) {
            self::assertContains('https://github.com/quirecms/framework/.github/workflows/runtime.yaml@refs/heads/main', $run['command'], 'Signed by the framework\'s runtime workflow, from main');
            self::assertContains('https://token.actions.githubusercontent.com', $run['command']);
        }

        self::assertCount(2, $this->said);
    }

    public function testARuntimeThatDoesNotCheckOutStopsTheBuild(): void
    {
        unset($this->signed['ghcr.io/quirecms/runtime:8.5']);
        $this->expectException(RuntimeImageNotTrusted::class);
        $this->expectExceptionMessageIsOrContains('ghcr.io/quirecms/runtime:8.5 didn\'t check out (it must be signed by https://github.com/quirecms/framework/.github/workflows/runtime.yaml@refs/heads/main): not building on it. cosign: Error: no matching signatures');
        $this->pinned();
    }

    public function testARegistryThatDoesntAnswerIsNoAlarm(): void
    {
        $this->runner->script['cosign verify'] = static fn (): ProcessResult => new ProcessResult([], 1, '', "Error: GET https://ghcr.io/v2/quirecms/runtime/manifests/8.5: dial tcp: lookup ghcr.io: no such host\n");

        try {
            $this->pinned();
            self::fail('It was built on.');
        } catch (RuntimeImageNotTrusted) {
            self::fail('Reported as a signature that didn\'t check out.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('couldn\'t be checked: the registry or Sigstore didn\'t answer (Error: GET https://ghcr.io/v2/quirecms/runtime/manifests/8.5: dial tcp: lookup ghcr.io: no such host)', $e->getMessage());
        }
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
        return $this->subject()->pinned($this->site, function (string $line): void {
            $this->said[] = $line;
        });
    }

    private function subject(): RuntimeImages
    {
        return new RuntimeImages($this->runner, new RuntimeFloors($this->connection, $this->schema), now: fn (): int => $this->now);
    }

    public function testTheBuilderIsntTakenForTheProductionBase(): void
    {
        // Signed by the same workflow: the tag of the production base moved to the builder's image
        $this->signed['ghcr.io/quirecms/runtime:8.5'] = self::BUILDER;
        $this->expectException(RuntimeImageNotTrusted::class);
        $this->expectExceptionMessageIsOrContains('RUNTIME names ghcr.io/quirecms/runtime:8.5, which is "Quire runtime (builder)", not "Quire runtime (runtime)"');
        $this->pinned();
    }

    public function testATagMovedBackToAnOlderSignedBuildIsntBuiltOn(): void
    {
        $this->pinned();
        $older = 'sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $this->images[$older] = ['Quire runtime (runtime)', '2026-08-03T02:55:00Z'];
        $this->signed['ghcr.io/quirecms/runtime:8.5'] = $older;
        $this->now += 3600;

        try {
            $this->pinned();
            self::fail('It was built on.');
        } catch (RuntimeImageNotTrusted $e) {
            self::assertStringContainsString('is now a build of 2026-08-03 02:55, older than one this server built on before (2026-10-26 02:55, '.self::RUNTIME.'): a tag moved back', $e->getMessage());
        }

        // A newer one raises the floor; a site that names a digest of its own chose it.
        $newer = 'sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';
        $this->images[$newer] = ['Quire runtime (runtime)', '2026-11-02T02:55:00Z'];
        $this->signed['ghcr.io/quirecms/runtime:8.5'] = $newer;
        self::assertSame('ghcr.io/quirecms/runtime:8.5@'.$newer, $this->pinned()['RUNTIME'] ?? null);
        self::assertSame($newer, new RuntimeFloors($this->connection, $this->schema)->of('ghcr.io/quirecms/runtime:8.5')['digest'] ?? null);
        $this->signed['ghcr.io/quirecms/runtime:8.5@'.self::RUNTIME] = self::RUNTIME;
        $this->dockerfile('ARG RUNTIME=ghcr.io/quirecms/runtime:8.5@'.self::RUNTIME."\nFROM \${RUNTIME}\n");
        self::assertSame(['RUNTIME' => 'ghcr.io/quirecms/runtime:8.5@'.self::RUNTIME], $this->pinned());
    }

    public function testAnOldBuildIsSaid(): void
    {
        $this->now = (int) new \DateTimeImmutable('2026-12-30')->format('U');
        $this->pinned();

        self::assertContains('Quire\'s runtime ghcr.io/quirecms/runtime:8.5 was built on 2026-10-26: Quire\'s runtime is built every week, so this one may miss security fixes.', $this->said);
    }

    public function testAnImageThatDoesntSayWhenItWasBuiltIsRefused(): void
    {
        $this->images[self::RUNTIME] = ['Quire runtime (runtime)', ''];
        $this->expectExceptionMessageIsOrContains('doesn\'t say when it was built');
        $this->pinned();
    }

    private function dockerfile(string $content): void
    {
        file_put_contents($this->site.'/Dockerfile', $content);
    }

    public function testAnImageCheckedInTheLastMinutesIsntCheckedAgain(): void
    {
        $runtime = $this->subject();
        $runtime->pinned($this->site, static function (): void {});
        $runtime->pinned($this->site, static function (): void {});
        self::assertCount(2, array_filter($this->runner->runs, static fn (array $run): bool => 'cosign' === $run['command'][0]), 'Each image checked once.');

        $this->now += 601;
        $this->signed['ghcr.io/quirecms/runtime:8.5'] = 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
        $this->images['sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd'] = ['Quire runtime (runtime)', '2026-11-02T02:55:00Z'];
        self::assertSame('ghcr.io/quirecms/runtime:8.5@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd', $runtime->pinned($this->site, static function (): void {})['RUNTIME'] ?? null, 'Then checked again: a newer build.');
    }
}
