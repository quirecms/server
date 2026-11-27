<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Release;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Whether a final release's image is its highest candidate's with only its version changed
 * (.github/release-promote-check.sh): deny by default, so anything else that differs is refused. The
 * registry is scripted (curl): it serves manifests and configs by their digest, made here as
 * BuildKit makes them (the promoted image adds ARG, ENV and LABEL entries that add no layer).
 *
 * @phpstan-type Config array{architecture: string, created: string, os: string, variant?: string, config: array{Env: list<string>, Entrypoint: list<string>, Cmd: list<string>, WorkingDir: string, Labels: array<string, string>, Healthcheck: array<string, mixed>, SomethingNew?: bool}, rootfs: array{type: string, diff_ids: list<string>}, history: list<array{created?: string, created_by: string, comment?: string, empty_layer?: bool}>}
 */
final class ReleasePromoteCheckTest extends TestCase
{
    private const string IMAGE = 'ghcr.io/quirecms/server';
    private const array LAYERS = ['sha256:1111111111111111111111111111111111111111111111111111111111111111', 'sha256:2222222222222222222222222222222222222222222222222222222222222222'];

    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-promote-check-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir([$this->root.'/blobs', $this->root.'/bin']);
        // curl … -o <file> <url>: the blob named by the URL's last part, or a 404 (22); a token for /token
        new Filesystem()->dumpFile($this->root.'/bin/curl', "#!/bin/sh\nout=\nprev=\nfor a; do [ \"\$prev\" = -o ] && out=\$a; prev=\$a; url=\$a; done\n"
            ."case \$url in */token\\?*) echo '{\"token\":\"t\"}'; exit 0;; esac\n"
            .'blob="'.$this->root."/blobs/\${url##*/}\"\n[ -f \"\$blob\" ] || exit 22\ncp \"\$blob\" \"\$out\"\n");
        chmod($this->root.'/bin/curl', 0o755);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testTheCandidatesImageWithOnlyItsVersionChangedIsApproved(): void
    {
        $tested = $this->image('0.7.1-rc.1');
        $final = $this->image('0.7.1', attestations: 2);

        $run = $this->check($tested, $final);

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertSame("digest=$final\n", $run->getOutput());
    }

    public function testAnExtraLayerIsRefused(): void
    {
        $final = $this->image('0.7.1', layers: [...self::LAYERS, 'sha256:3333333333333333333333333333333333333333333333333333333333333333']);

        self::assertStringContainsString('linux/amd64: the final\'s manifest differs from the candidate\'s in more than its config', $this->refused($this->image('0.7.1-rc.1'), $final));
    }

    public function testAnotherLayerInPlaceOfATestedOneIsRefused(): void
    {
        $final = $this->image('0.7.1', layers: [self::LAYERS[0], 'sha256:4444444444444444444444444444444444444444444444444444444444444444'], diffIds: [self::LAYERS[0], 'sha256:4444444444444444444444444444444444444444444444444444444444444444']);

        self::assertStringContainsString('its layers are not the tested ones', $this->refused($this->image('0.7.1-rc.1'), $final));
    }

    public function testAChangedEnvironmentIsRefused(): void
    {
        $final = $this->image('0.7.1', change: static function (array $config): array {
            $config['config']['Env'][] = 'APP_DEBUG=1';

            return $config;
        });

        self::assertStringContainsString('linux/amd64: the final\'s config differs from the candidate\'s in more than its version', $this->refused($this->image('0.7.1-rc.1'), $final));
    }

    public function testAChangedEntrypointIsRefused(): void
    {
        $final = $this->image('0.7.1', change: static function (array $config): array {
            $config['config']['Entrypoint'] = ['/bin/sh'];

            return $config;
        });

        self::assertStringContainsString('in more than its version', $this->refused($this->image('0.7.1-rc.1'), $final));
    }

    public function testAnUnknownNewFieldIsRefused(): void
    {
        // Deny by default: a field nobody listed, in the config or beside it
        $inConfig = $this->image('0.7.1', change: static function (array $config): array {
            $config['config']['SomethingNew'] = true;

            return $config;
        });
        $beside = $this->image('0.7.1', change: static function (array $config): array {
            $config['variant'] = 'v8';

            return $config;
        });

        self::assertStringContainsString('in more than its version', $this->refused($this->image('0.7.1-rc.1'), $inConfig));
        self::assertStringContainsString('in more than its version', $this->refused($this->image('0.7.1-rc.1'), $beside));
    }

    public function testAChangedLabelOtherThanTheVersionIsRefused(): void
    {
        $final = $this->image('0.7.1', change: static function (array $config): array {
            $config['config']['Labels']['org.opencontainers.image.source'] = 'https://github.com/someone/server';

            return $config;
        });

        self::assertStringContainsString('in more than its version', $this->refused($this->image('0.7.1-rc.1'), $final));
    }

    public function testAMissingPlatformIsRefused(): void
    {
        $final = $this->image('0.7.1', platforms: ['amd64']);

        self::assertStringContainsString('The final\'s image is for linux/amd64 but the candidate\'s for linux/amd64 linux/arm64', $this->refused($this->image('0.7.1-rc.1'), $final));
    }

    public function testAHistoryEntryThatAddsALayerIsRefused(): void
    {
        $final = $this->image('0.7.1', change: static function (array $config): array {
            $config['history'][] = ['created_by' => 'RUN echo', 'comment' => 'buildkit.dockerfile.v0'];

            return $config;
        });

        self::assertStringContainsString('linux/amd64: the final\'s history isn\'t the candidate\'s with only entries that add no layer', $this->refused($this->image('0.7.1-rc.1'), $final));
    }

    public function testARewrittenHistoryIsRefused(): void
    {
        $final = $this->image('0.7.1', change: static function (array $config): array {
            $config['history'][0]['created_by'] = 'COPY something else';

            return $config;
        });

        self::assertStringContainsString('history isn\'t the candidate\'s', $this->refused($this->image('0.7.1-rc.1'), $final));
    }

    public function testAnImageThatSaysAnotherVersionIsRefused(): void
    {
        $tested = $this->image('0.7.1-rc.1');

        self::assertStringContainsString('the final\'s image says it\'s "0.7.1 0.7.1" (environment, label), not 0.7.2', $this->refused($tested, $this->image('0.7.1'), '0.7.2'));
        // Its label left as the candidate's
        $label = $this->image('0.7.1', change: static function (array $config): array {
            $config['config']['Labels']['org.opencontainers.image.version'] = '0.7.1-rc.1';

            return $config;
        });
        self::assertStringContainsString('says it\'s "0.7.1 0.7.1-rc.1"', $this->refused($tested, $label));
    }

    public function testTheCandidatesImageUnchangedIsRefused(): void
    {
        $tested = $this->image('0.7.1-rc.1');

        self::assertStringContainsString('its version wasn\'t changed', $this->refused($tested, $tested));
    }

    public function testWhatTheRegistryServesMustBeWhatItsDigestSays(): void
    {
        $tested = $this->image('0.7.1-rc.1');
        $final = $this->image('0.7.1');
        file_put_contents($this->root.'/blobs/'.$final, '{"mediaType":"application/vnd.oci.image.index.v1+json","manifests":[]}');

        self::assertStringContainsString('isn\'t what its digest says', $this->refused($tested, $final));
    }

    public function testAnImageThatIsntThereIsRefused(): void
    {
        self::assertStringContainsString('can\'t be read', $this->refused($this->image('0.7.1-rc.1'), 'sha256:'.str_repeat('9', 64)));
    }

    public function testAReleaseCandidatesVersionIsntPromotedTo(): void
    {
        self::assertStringContainsString('isn\'t a final release\'s version', $this->refused($this->image('0.7.1-rc.1'), $this->image('0.7.1-rc.2'), '0.7.1-rc.2'));
    }

    public function testTheWorkflowShipsOnlyWhatTheCheckApproved(): void
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 2).'/.github/workflows/release.yaml');
        $dockerfile = (string) file_get_contents(\dirname(__DIR__, 2).'/.github/promote.Dockerfile');

        self::assertStringContainsString('.github/release-promote-check.sh "$IMAGE" "$TESTED" "$FINAL" "$VERSION"', $workflow);
        self::assertStringContainsString('PROMOTED: ${{ steps.promote_check.outputs.digest }}', $workflow, 'The digest shipped is the one the check approved.');
        self::assertStringContainsString('grep -qx "IMAGE=$IMAGE@$APPROVED" dist/release.env', $workflow);
        self::assertStringContainsString('TESTED=${{ env.IMAGE }}@${{ steps.source.outputs.digest }}', $workflow, 'The candidate by the digest release-image.sh checked, never a tag.');
        self::assertDoesNotMatchRegularExpression('/Set up QEMU \(arm64\)\n\s+if:/', $workflow, 'QEMU for promoted images too: the arm64 one is run.');
        self::assertStringContainsString('out=$(docker run --rm --platform "$platform" --network none "$IMAGE@$DIGEST" bin/console server:version)', $workflow);
        self::assertLessThan(strpos($workflow, '- name: Sign the image'), strpos($workflow, '- name: It runs, and says it\'s this release'), 'Run before it\'s signed.');
        self::assertMatchesRegularExpression('/^ARG TESTED$/m', $dockerfile, 'No default.');
        self::assertDoesNotMatchRegularExpression('/^RUN /m', $dockerfile, 'Nothing built again.');
    }

    private function refused(string $tested, string $final, string $version = '0.7.1'): string
    {
        $run = $this->check($tested, $final, $version);
        self::assertSame(1, $run->getExitCode(), $run->getOutput());
        self::assertSame('', $run->getOutput(), 'Nothing for the workflow to go on with.');

        return $run->getErrorOutput();
    }

    private function check(string $tested, string $final, string $version = '0.7.1'): Process
    {
        $run = new Process([\dirname(__DIR__, 2).'/.github/release-promote-check.sh', self::IMAGE, $tested, $final, $version], $this->root, ['PATH' => $this->root.'/bin:'.getenv('PATH')]);
        $run->run();

        return $run;
    }

    /**
     * An image index, for each platform a manifest and a config as BuildKit makes them; a final
     * (a version without "-rc") as the promote build makes it from the candidate. Its digest.
     *
     * @param list<string>                    $platforms
     * @param list<string>|null               $layers
     * @param list<string>|null               $diffIds
     * @param (\Closure(Config): Config)|null $change
     */
    private function image(string $version, array $platforms = ['amd64', 'arm64'], int $attestations = 1, ?array $layers = null, ?array $diffIds = null, ?\Closure $change = null): string
    {
        $manifests = [];

        foreach ($platforms as $architecture) {
            $config = $this->config($architecture, $version, $diffIds ?? self::LAYERS);
            $config = $change instanceof \Closure ? $change($config) : $config;
            $configDigest = $this->store((string) json_encode($config, \JSON_UNESCAPED_SLASHES));
            $manifest = $this->store((string) json_encode([
                'schemaVersion' => 2,
                'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
                'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => $configDigest, 'size' => 1000],
                'layers' => array_map(static fn (string $layer): array => ['mediaType' => 'application/vnd.oci.image.layer.v1.tar+gzip', 'digest' => $layer, 'size' => 100], $layers ?? self::LAYERS),
            ], \JSON_UNESCAPED_SLASHES));
            $manifests[] = ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => $manifest, 'size' => 500, 'platform' => ['architecture' => $architecture, 'os' => 'linux']];
        }

        // Attestations (SBOM, provenance) differ for every build, and aren't platforms
        for ($i = 0; $i < $attestations; ++$i) {
            $manifests[] = ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => 'sha256:'.hash('sha256', $version.$i), 'size' => 800, 'annotations' => ['vnd.docker.reference.digest' => $manifests[0]['digest'], 'vnd.docker.reference.type' => 'attestation-manifest'], 'platform' => ['architecture' => 'unknown', 'os' => 'unknown']];
        }

        return $this->store((string) json_encode(['schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.index.v1+json', 'manifests' => $manifests], \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param list<string> $diffIds
     *
     * @return Config
     */
    private function config(string $architecture, string $version, array $diffIds): array
    {
        $tested = str_contains($version, '-') ? $version : $version.'-rc.1';
        $history = [
            ['created' => '2026-11-25T20:00:00Z', 'created_by' => 'ADD alpine-minirootfs.tar.gz / # buildkit', 'comment' => 'buildkit.dockerfile.v0'],
            ['created' => '2026-11-25T20:00:00Z', 'created_by' => 'ENV QUIRE_SERVER_VERSION='.$tested, 'comment' => 'buildkit.dockerfile.v0', 'empty_layer' => true],
            ['created' => '2026-11-25T20:00:00Z', 'created_by' => 'COPY src src/ # buildkit', 'comment' => 'buildkit.dockerfile.v0'],
        ];

        if ($tested !== $version) {
            foreach (['ARG QUIRE_SERVER_VERSION=', 'ENV QUIRE_SERVER_VERSION=', 'LABEL org.opencontainers.image.version='] as $instruction) {
                $history[] = ['created' => '2026-11-26T18:00:00Z', 'created_by' => $instruction.$version, 'comment' => 'buildkit.dockerfile.v0', 'empty_layer' => true];
            }
        }

        return [
            'architecture' => $architecture,
            'created' => $tested === $version ? '2026-11-25T20:00:00Z' : '2026-11-26T18:00:00Z',
            'os' => 'linux',
            'config' => [
                'Env' => ['PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'APP_ENV=prod', 'QUIRE_SERVER_VERSION='.$version],
                'Entrypoint' => ['/sbin/tini', '--', 'quire-server-entrypoint'],
                'Cmd' => ['bin/console', 'server:agent'],
                'WorkingDir' => '/opt/quire-server',
                'Labels' => ['org.opencontainers.image.title' => 'Quire Server', 'org.opencontainers.image.source' => 'https://github.com/quirecms/server', 'org.opencontainers.image.version' => $version],
                'Healthcheck' => ['Test' => ['CMD-SHELL', 'php -r 1'], 'Interval' => 30000000000],
            ],
            'rootfs' => ['type' => 'layers', 'diff_ids' => $diffIds],
            'history' => $history,
        ];
    }

    private function store(string $content): string
    {
        $digest = 'sha256:'.hash('sha256', $content);
        file_put_contents($this->root.'/blobs/'.$digest, $content);

        return $digest;
    }
}
