<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Release;

use PHPUnit\Framework\TestCase;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\SymfonyProcessRunner;
use Quire\Server\Release\GitHubReleases;
use Quire\Server\Release\Releases;
use Quire\Server\Release\ReleaseVerificationFailed;
use Quire\Server\Release\Version;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Getting a release and checking it, with GitHub and cosign scripted: above all, every way a release
 * can fail to check out stops it before any of it is used.
 */
final class ReleasesTest extends TestCase
{
    private const string IMAGE = 'ghcr.io/quirecms/server@sha256:4444444444444444444444444444444444444444444444444444444444444444';

    private string $tmp;
    private string $work;

    /** @var array<string, string> What GitHub serves, by file name */
    private array $served = [];
    private bool $signed = true;
    private string $latest = 'https://github.com/quirecms/server/releases/tag/v0.2.0';
    private ScriptedRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/quire-releases-'.bin2hex(random_bytes(6));
        $this->work = $this->tmp.'/work';
        $this->served = ['quire-server-0.2.0.tar.gz' => $this->archive(['quire-server-0.2.0/compose.yaml' => 'name: quire-server', 'quire-server-0.2.0/bin/quire-server' => '#!/bin/sh'])];
        $this->serveReleaseEnv();
        $real = new SymfonyProcessRunner();

        $this->runner = new ScriptedRunner([
            'curl -fsSL' => function (array $command): ProcessResult {
                $url = (string) end($command);

                if (\in_array('%{url_effective}', $command, true)) {
                    return ScriptedRunner::ok($command, $this->latest);
                }

                self::assertMatchesRegularExpression('#^https://github\.com/quirecms/server/releases/download/v0\.2\.0/[^/]+\z#', $url);
                $file = basename($url);

                if (!isset($this->served[$file])) {
                    return ScriptedRunner::failed($command, 'curl: (22) The requested URL returned error: 404');
                }

                file_put_contents($this->after($command, '-o'), $this->served[$file]);

                return ScriptedRunner::ok($command);
            },
            'cosign verify-blob' => fn (array $command): ProcessResult => $this->signed ? ScriptedRunner::ok($command) : ScriptedRunner::failed($command, 'Error: none of the expected identities matched'),
            'tar -tzf' => static fn (array $command): ProcessResult => $real->run($command),
            'tar -xzf' => static fn (array $command): ProcessResult => $real->run($command),
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmp);
    }

    public function testAReleaseThatChecksOutIsExtracted(): void
    {
        $said = [];
        $release = new Releases(new GitHubReleases($this->runner), $this->runner)->fetch(new Version('0.2.0'), $this->work, static function (string $line) use (&$said): void {
            $said[] = $line;
        });

        self::assertSame('0.2.0', $release->version->value);
        self::assertSame(self::IMAGE, $release->image);
        self::assertFileExists($release->files.'/compose.yaml');
        self::assertFileExists($release->files.'/bin/quire-server');
        self::assertSame(['Its signature checks out (Sigstore: signed by its release workflow, for v0.2.0)'], $said);

        // Signed by this repository's release workflow, for exactly that tag, through GitHub's identity provider
        $cosign = $this->command('cosign verify-blob');
        self::assertSame('https://github.com/quirecms/server/.github/workflows/release.yaml@refs/tags/v0.2.0', $this->after($cosign, '--certificate-identity'));
        self::assertSame('https://token.actions.githubusercontent.com', $this->after($cosign, '--certificate-oidc-issuer'));
        self::assertSame($this->work.'/release.env', end($cosign));

        // Only HTTPS, for every download
        foreach ($this->runner->runs as $run) {
            if ('curl' === $run['command'][0]) {
                self::assertSame('=https', $this->after($run['command'], '--proto'));
            }
        }
    }

    public function testAReleaseNotSignedByItsWorkflowIsRefused(): void
    {
        $this->signed = false;
        $this->refused('/The signature of Quire Server 0\.2\.0 didn\'t check out/');
        self::assertNotContains('quire-server-0.2.0.tar.gz', array_map(static fn (array $run): string => basename((string) end($run['command'])), $this->runner->runs), 'Nothing else is downloaded.');
    }

    public function testAnArchiveThatIsNotTheSignedOneIsRefused(): void
    {
        $this->served['quire-server-0.2.0.tar.gz'] = $this->archive(['quire-server-0.2.0/compose.yaml' => 'name: evil', 'quire-server-0.2.0/bin/quire-server' => '#!/bin/sh']);
        $this->refused('/its checksum differs/');
        self::assertDirectoryDoesNotExist($this->work.'/files/bin', 'Nothing of it is extracted.');
    }

    public function testAReleaseEnvNamingAnotherVersionIsRefused(): void
    {
        // A genuine release.env of another version, served for this one
        $this->serveReleaseEnv(version: '0.1.9');
        $this->refused('/names another version/');
    }

    public function testAnImageOfAnotherRepositoryIsRefused(): void
    {
        $this->serveReleaseEnv(image: 'ghcr.io/evil/server@sha256:4444444444444444444444444444444444444444444444444444444444444444');
        $this->refused('/names no image/');
    }

    public function testAnImageByTagIsRefused(): void
    {
        $this->serveReleaseEnv(image: 'ghcr.io/quirecms/server:0.2.0');
        $this->refused('/names no image/');
    }

    public function testAnArchiveNameWithAPathIsRefused(): void
    {
        $this->serveReleaseEnv(archive: '../../server.env');
        $this->refused('/names no archive/');
    }

    public function testAnArchiveWithPathsOutsideIsRefused(): void
    {
        $this->served['quire-server-0.2.0.tar.gz'] = $this->archive(['quire-server-0.2.0/compose.yaml' => 'x', 'quire-server-0.2.0/bin/quire-server' => 'x', 'quire-server-0.2.0/../../escaped' => 'x']);
        $this->serveReleaseEnv();
        $this->refused('/a path outside it/');
        self::assertFileDoesNotExist($this->tmp.'/escaped');
    }

    public function testAnArchiveThatIsNotTheServersIsRefused(): void
    {
        $this->served['quire-server-0.2.0.tar.gz'] = $this->archive(['quire-server-0.2.0/README.md' => 'x']);
        $this->serveReleaseEnv();
        $this->refused('/isn\'t Quire Server\'s/');
    }

    public function testAMissingReleaseIsRefused(): void
    {
        unset($this->served['release.env.sigstore.json']);
        $this->refused('/couldn\'t be downloaded/');
    }

    public function testTheLatestRelease(): void
    {
        self::assertSame('0.2.0', new Releases(new GitHubReleases($this->runner), $this->runner)->latest()?->value);

        // No release yet: GitHub's "latest" leads to the list of releases.
        $this->latest = 'https://github.com/quirecms/server/releases';
        self::assertNull(new Releases(new GitHubReleases($this->runner), $this->runner)->latest());
    }

    public function testALatestTagThatIsNotAVersionIsRefused(): void
    {
        $this->latest = 'https://github.com/quirecms/server/releases/tag/v0.2.0;rm';
        $this->expectException(ReleaseVerificationFailed::class);
        new Releases(new GitHubReleases($this->runner), $this->runner)->latest();
    }

    public function testALatestReleaseElsewhereIsIgnored(): void
    {
        $this->latest = 'https://github.com/evil/server/releases/tag/v9.0.0';
        self::assertNull(new Releases(new GitHubReleases($this->runner), $this->runner)->latest());
    }

    private function refused(string $message): void
    {
        try {
            new Releases(new GitHubReleases($this->runner), $this->runner)->fetch(new Version('0.2.0'), $this->work, static function (): void {});
            self::fail('The release was used.');
        } catch (ReleaseVerificationFailed $e) {
            self::assertMatchesRegularExpression($message, $e->getMessage());
        }
    }

    private function serveReleaseEnv(string $version = '0.2.0', string $image = self::IMAGE, string $archive = 'quire-server-0.2.0.tar.gz'): void
    {
        $this->served['release.env'] = \sprintf("VERSION=%s\nIMAGE=%s\nARCHIVE=%s\nARCHIVE_SHA256=%s\n", $version, $image, $archive, hash('sha256', $this->served['quire-server-0.2.0.tar.gz']));
        $this->served['release.env.sigstore.json'] = '{}';
    }

    /**
     * @param array<string, string> $files
     */
    private function archive(array $files): string
    {
        $source = $this->tmp.'/archive-'.bin2hex(random_bytes(4));
        $paths = [];

        foreach ($files as $path => $content) {
            // A path with ".." is written as such in the archive (tar's --transform), not resolved.
            $local = str_replace('../', 'up/', $path);
            new Filesystem()->dumpFile($source.'/'.$local, $content);
            $paths[$local] = $path;
        }

        $tar = $source.'.tar.gz';
        $transforms = [];

        foreach ($paths as $local => $path) {
            if ($local !== $path) {
                $transforms[] = '--transform=s#^'.$local.'$#'.$path.'#';
            }
        }

        new SymfonyProcessRunner()->run(['tar', '-czf', $tar, '-C', $source, ...$transforms, ...array_keys($paths)])->orFail('Making the archive');

        return (string) file_get_contents($tar);
    }

    /**
     * @return list<string> The command run with this key
     */
    private function command(string $key): array
    {
        foreach ($this->runner->runs as $run) {
            if (ScriptedRunner::key($run['command']) === $key) {
                return $run['command'];
            }
        }

        self::fail('Not run: '.$key);
    }

    /**
     * The value after an option in a command, e.g. the file after "-o".
     *
     * @param list<string> $command
     */
    private function after(array $command, string $option): string
    {
        $at = array_search($option, $command, true);
        self::assertIsInt($at, $option.' is given');

        return $command[$at + 1] ?? '';
    }
}
