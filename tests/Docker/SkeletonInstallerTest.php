<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\TestCase;
use Quire\Server\Docker\SkeletonInstaller;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Getting a site's files from quire/skeleton: a download that fails on the way is tried once more.
 */
final class SkeletonInstallerTest extends TestCase
{
    /** What GitHub answered on 2026-11-07, for a minute at a time */
    private const string GATEWAY_TIMEOUT = "In CurlDownloader.php line 686:\n  The \"https://api.github.com/repos/quirecms/skeleton/zipball/af0e8f9abbf819343fcac815d8c58d62abdd4330\" file could not be downloaded (HTTP/2 504 )\n";

    private string $root;
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/skeleton-installer-'.bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->directory = $this->root.'/jane';
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testADownloadThatFailsOnTheWayIsTriedOnceMore(): void
    {
        $runner = $this->runner([self::GATEWAY_TIMEOUT, null]);
        $said = '';

        $version = new SkeletonInstaller($runner, retrySeconds: 0.0)->create($this->directory, '^0.1', static function (string $line) use (&$said): void { $said .= $line; });

        self::assertSame('0.1.2', $version);
        self::assertCount(2, $runner->runs);
        self::assertFileExists($this->directory.'/composer.json');
        self::assertFileDoesNotExist($this->directory.'/half-made', 'What the first try left is gone.');
        self::assertStringContainsString('A download failed on the way (api.github.com answered HTTP 504', $said);
        self::assertStringContainsString('Trying again in 0 s', $said);
    }

    public function testASecondFailureFails(): void
    {
        $runner = $this->runner([self::GATEWAY_TIMEOUT, self::GATEWAY_TIMEOUT]);

        try {
            new SkeletonInstaller($runner, retrySeconds: 0.0)->create($this->directory, '^0.1');
            self::fail('Two failures fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Getting the site\'s files', $e->getMessage());
        }

        self::assertCount(2, $runner->runs);
    }

    public function testAFailureThatDoesntPassByItselfIsntTriedAgain(): void
    {
        // A version that doesn't exist: trying again only makes the owner wait
        $runner = $this->runner(["Could not find package quire/skeleton with version ^9.9.\n"]);

        try {
            new SkeletonInstaller($runner, retrySeconds: 0.0)->create($this->directory, '^9.9');
            self::fail('It fails.');
        } catch (\RuntimeException) {
        }

        self::assertCount(1, $runner->runs);
    }

    /**
     * Composer's create-project, failing with each error in turn (null: it works).
     *
     * @param list<string|null> $errors
     */
    private function runner(array $errors): ScriptedRunner
    {
        return new ScriptedRunner([
            'composer create-project' => static function (array $command) use (&$errors): ProcessResult {
                $error = array_shift($errors);
                $directory = $command[3];
                self::assertDirectoryDoesNotExist($directory, 'Each try starts with no folder.');
                mkdir($directory);

                if (null !== $error) {
                    touch($directory.'/half-made');

                    return ScriptedRunner::failed($command, $error);
                }

                file_put_contents($directory.'/composer.json', '{}');

                return ScriptedRunner::ok($command, "Installing quire/skeleton (v0.1.2)\n");
            },
        ]);
    }
}
