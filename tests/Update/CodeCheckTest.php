<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\TestCase;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Release\GitHubReleases;
use Quire\Server\Release\QuireFeed;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Update\CodeCheck;
use Quire\Server\Update\CodeNotReleased;
use Quire\Server\Update\ContractNotKept;
use Quire\Server\Update\ReleaseCheck;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A site's code against Quire's signed feed (D-38): its lock's quire/* packages at the commits the
 * feed names, from GitHub's repositories of them, and a skeleton with exactly the feed's files. The
 * feed comes as Quire Server gets it (GitHub, its signature checked), scripted.
 */
final class CodeCheckTest extends TestCase
{
    private const string CORE = 'd8efc27c31e667e1166b826f110e807044ef9c26';
    private const string ADMIN = '326fce23e4d1563ab44d334426e1a3e1b010deae';

    private string $root;
    private bool $reachable = true;
    private bool $tampered = false;

    /** @var list<array<string, mixed>> The feed's releases, newest first */
    private array $releases;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-code-'.bin2hex(random_bytes(6));
        $this->releases = [
            $this->release('0.1.3', ['quire/admin' => self::ADMIN, 'quire/core' => self::CORE], ['Dockerfile' => hash('sha256', 'FROM runtime'), 'composer.json' => hash('sha256', '{}'), 'config/a.yaml' => hash('sha256', 'a: 1'), '.github/workflows/ci.yaml' => hash('sha256', 'ci')]),
            // Before feeds named commits
            $this->release('0.1.2', null, null),
        ];
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testALockWithQuiresCodeAsReleasedPasses(): void
    {
        self::assertSame([], $this->check()->lock($this->lock([$this->package('quire/core', '0.1.3', self::CORE), $this->package('quire/admin', 'v0.1.3', self::ADMIN), ['name' => 'symfony/yaml', 'version' => 'v8.1.10']])));
    }

    public function testAMovedTagIsRefused(): void
    {
        $this->expectException(CodeNotReleased::class);
        $this->expectExceptionMessageIsOrContains('quire/core 0.1.3 isn\'t the code Quire released: its download names commit '.str_repeat('e', 40).', where Quire\'s release feed says commit '.self::CORE.' of quirecms/core');
        $this->check()->lock($this->lock([$this->package('quire/core', '0.1.3', str_repeat('e', 40))]));
    }

    public function testADownloadFromElsewhereIsRefusedThoughItNamesTheRightCommit(): void
    {
        // Composer doesn't check a zip against the commit its lock names
        $package = $this->package('quire/core', '0.1.3', self::CORE);
        $package['dist']['url'] = 'https://evil.example/core.zip';

        $this->expectExceptionMessageIsOrContains('its download comes from https://evil.example/core.zip');
        $this->check()->lock($this->lock([$package]));
    }

    public function testASourceFromElsewhereIsRefused(): void
    {
        $package = $this->package('quire/core', '0.1.3', self::CORE);
        $package['source']['url'] = 'https://github.com/someone/core.git';

        $this->expectExceptionMessageIsOrContains('its source is https://github.com/someone/core.git');
        $this->check()->lock($this->lock([$package]));
    }

    public function testAReleaseThatKeepsANewerServerContractIsNeverBuilt(): void
    {
        array_unshift($this->releases, $this->release('0.2.0', ['quire/core' => self::CORE], null, ReleaseCheck::CONTRACT + 1));

        $this->expectException(ContractNotKept::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('quire/core 0.2.0 keeps server contract %d; this server knows up to %d: update the server first ("quire-server update"). Nothing was built.', ReleaseCheck::CONTRACT + 1, ReleaseCheck::CONTRACT));
        // Its commit is the one the feed names: the contract alone stops it.
        $this->check()->lock($this->lock([$this->package('quire/core', '0.2.0', self::CORE)]));
    }

    public function testAReleaseThatKeepsTheServersContractIsBuilt(): void
    {
        array_unshift($this->releases, $this->release('0.2.0', ['quire/core' => self::CORE], null, ReleaseCheck::CONTRACT));

        self::assertSame([], $this->check()->lock($this->lock([$this->package('quire/core', '0.2.0', self::CORE)])));
    }

    public function testWhatTheFeedDoesntVouchForIsBuiltAndSaid(): void
    {
        self::assertSame([
            'quire/core 0.1.2 isn\'t vouched for by Quire\'s release feed (a release from before the feed named commits): built as it is.',
            'quire/admin dev-main isn\'t vouched for by Quire\'s release feed (not one of its releases): built as it is.',
        ], $this->check()->lock($this->lock([$this->package('quire/core', 'v0.1.2', self::CORE), $this->package('quire/admin', 'dev-main', str_repeat('f', 40))])));
    }

    public function testAFeedThatDoesntCheckOutStopsIt(): void
    {
        $this->tampered = true;

        $this->expectException(CodeNotReleased::class);
        $this->check()->lock($this->lock([$this->package('quire/core', '0.1.3', self::CORE)]));
    }

    public function testAFeedThatCantBeGotIsSaid(): void
    {
        $this->reachable = false;

        self::assertStringContainsString('couldn\'t be read', $this->check()->lock($this->lock([$this->package('quire/core', '0.1.3', self::CORE)]))[0] ?? '');
    }

    public function testALockWithoutQuireIsntChecked(): void
    {
        $this->reachable = false;

        self::assertSame([], $this->check()->lock($this->lock([['name' => 'symfony/yaml', 'version' => 'v8.1.10']])), 'Not even the feed is read.');
    }

    public function testASkeletonAsReleasedPasses(): void
    {
        $this->skeleton(['Dockerfile' => 'FROM runtime', 'composer.json' => '{}', 'config/a.yaml' => 'a: 1']);

        self::assertSame([], $this->check()->skeleton($this->root.'/skeleton', 'v0.1.3'), 'Without .github: archives leave it out.');
    }

    public function testASkeletonThatDiffersIsRefusedWithWhatDiffers(): void
    {
        foreach ([
            [['Dockerfile' => 'FROM evil', 'composer.json' => '{}', 'config/a.yaml' => 'a: 1'], 'Dockerfile differs'],
            [['Dockerfile' => 'FROM runtime', 'composer.json' => '{}', 'config/a.yaml' => 'a: 1', 'src/Planted.php' => '<?php'], 'src/Planted.php differs'],
            [['Dockerfile' => 'FROM runtime', 'composer.json' => '{}'], 'config/a.yaml differs'],
        ] as [$files, $expected]) {
            new Filesystem()->remove($this->root.'/skeleton');
            $this->skeleton($files);

            try {
                $this->check()->skeleton($this->root.'/skeleton', '0.1.3');
                self::fail('It was taken: '.$expected);
            } catch (CodeNotReleased $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testALinkInTheSkeletonIsNoFileOfIts(): void
    {
        $this->skeleton(['Dockerfile' => 'FROM runtime', 'composer.json' => '{}']);
        new Filesystem()->dumpFile($this->root.'/elsewhere', 'a: 1');
        new Filesystem()->mkdir($this->root.'/skeleton/config');
        symlink($this->root.'/elsewhere', $this->root.'/skeleton/config/a.yaml');

        $this->expectExceptionMessageIsOrContains('config/a.yaml differs');
        $this->check()->skeleton($this->root.'/skeleton', '0.1.3');
    }

    public function testASkeletonTheFeedHasNoFilesOfIsSaid(): void
    {
        $this->skeleton(['Dockerfile' => 'anything']);

        self::assertSame(['Quire\'s skeleton 0.1.2 isn\'t vouched for by its release feed: used as it is.'], $this->check()->skeleton($this->root.'/skeleton', '0.1.2'));
    }

    /**
     * @param array<string, string> $files
     */
    private function skeleton(array $files): void
    {
        foreach ($files as $path => $content) {
            new Filesystem()->dumpFile($this->root.'/skeleton/'.$path, $content);
        }
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function lock(array $packages): string
    {
        $file = $this->root.'/composer.lock';
        new Filesystem()->dumpFile($file, (string) json_encode(['packages' => $packages, 'packages-dev' => []]));

        return $file;
    }

    /**
     * A package as Packagist gives it for a commit of its repository.
     *
     * @return array{name: string, version: string, source: array<string, string>, dist: array<string, string>}
     */
    private function package(string $name, string $version, string $commit): array
    {
        $repository = substr($name, \strlen('quire/'));

        return [
            'name' => $name,
            'version' => $version,
            'source' => ['type' => 'git', 'url' => 'https://github.com/quirecms/'.$repository.'.git', 'reference' => $commit],
            'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/quirecms/'.$repository.'/zipball/'.$commit, 'reference' => $commit, 'shasum' => ''],
        ];
    }

    /**
     * @param array<string, string>|null $packages
     * @param array<string, string>|null $files
     *
     * @return array<string, mixed>
     */
    private function release(string $version, ?array $packages, ?array $files, int $contract = 1): array
    {
        return [
            'version' => $version, 'date' => '2026-11-04', 'security' => [], 'notes' => 'Notes.', 'upgrade' => null,
            'requires' => ['php' => '>=8.5.11', 'postgresql' => 18, 'server_contract' => $contract],
            ...(null === $files ? [] : ['skeleton' => ['files' => $files, 'changed' => null]]),
            ...(null === $packages ? [] : ['packages' => $packages]),
        ];
    }

    private function check(): CodeCheck
    {
        $runner = new ScriptedRunner([
            'curl -fsSL' => function (array $command): ProcessResult {
                if (!$this->reachable) {
                    return ScriptedRunner::failed($command, 'curl: (6) Could not resolve host: github.com');
                }

                if (\in_array('%{url_effective}', $command, true)) {
                    // The latest release is the feed's newest
                    $latest = $this->releases[0]['version'] ?? null;
                    \assert(\is_string($latest));

                    return ScriptedRunner::ok($command, 'https://github.com/quirecms/framework/releases/tag/v'.$latest);
                }

                $at = array_search('-o', $command, true);
                $last = (string) end($command);
                file_put_contents($command[\is_int($at) ? $at + 1 : 0], str_ends_with($last, '.json') && !str_ends_with($last, 'sigstore.json') ? json_encode(['schema' => 1, 'releases' => $this->releases]) : '{}');

                return ScriptedRunner::ok($command);
            },
            'cosign verify-blob' => fn (array $command): ProcessResult => $this->tampered ? ScriptedRunner::failed($command, 'Error: none of the expected identities matched') : ScriptedRunner::ok($command),
        ]);

        return new CodeCheck(new QuireFeed(new GitHubReleases($runner)));
    }
}
