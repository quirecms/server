<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Process\Failures;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates a site's folder from quire/skeleton, with Composer, without running any of its code: no
 * scripts, no plugins, no dependencies installed (they're installed inside the site's image build,
 * in Docker, where they can't touch the server).
 *
 * A download that fails on the way (a host's 5xx or 429, a connection cut: GitHub answers 504 now and
 * then for a minute) is tried once more, 30 s later, as a site's build is (Compose::build).
 */
final readonly class SkeletonInstaller
{
    /** "^0.1", "~1.2.3", "0.2.0", "1.0.0-beta2" or "dev-main" */
    private const string VERSION = '/^(?:[\^~]?\d+\.\d+(?:\.\d+)?(?:-[a-z0-9.]+)?|dev-main)\z/';

    public function __construct(
        private ProcessRunner $runner,
        private float $retrySeconds = 30.0,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $output
     *
     * @return string|null The version Composer installed ("0.1.2"), when it said
     */
    public function create(string $directory, string $version, ?\Closure $output = null): ?string
    {
        if (1 !== preg_match(self::VERSION, $version)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a Quire version, e.g. "^0.1" or "0.2.0".', $version));
        }

        if (file_exists($directory)) {
            throw new \DomainException(\sprintf('"%s" exists already.', $directory));
        }

        $home = sys_get_temp_dir().'/composer-'.bin2hex(random_bytes(6));
        $said = '';
        $say = static function (string $line) use ($output, &$said): void {
            $said .= $line;

            if ($output instanceof \Closure) {
                $output($line);
            }
        };

        try {
            $result = $this->createProject($directory, $version, $home, $say);

            if (!$result->successful() && Failures::transient($result->output."\n".$result->errorOutput)) {
                // What the first try left (it made the folder, which didn't exist before), then once more
                new Filesystem()->remove($directory);
                $say(\sprintf("A download failed on the way (%s). Trying again in %d s…\n", Failures::explain($result->output."\n".$result->errorOutput)['why'] ?? 'a host didn\'t answer', (int) $this->retrySeconds));
                usleep((int) ($this->retrySeconds * 1_000_000));
                $result = $this->createProject($directory, $version, $home, $say);
            }

            $result->orFail('Getting the site\'s files (composer create-project quire/skeleton)');
            $said .= $result->output.$result->errorOutput;
        } finally {
            new Filesystem()->remove($home);
        }

        chmod($directory, 0o750);

        // "Installing quire/skeleton (v0.1.2)"
        return 1 === preg_match('#Installing quire/skeleton \(v?([0-9][0-9A-Za-z.-]*)\)#', $said, $match) ? $match[1] : null;
    }

    /**
     * @param \Closure(string): void $say
     */
    private function createProject(string $directory, string $version, string $home, \Closure $say): ProcessResult
    {
        return $this->runner->run(
            ['composer', 'create-project', 'quire/skeleton', $directory, $version, '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--no-ansi'],
            ['COMPOSER_HOME' => $home, 'COMPOSER_CACHE_DIR' => $home.'/cache', 'COMPOSER_NO_AUDIT' => '1'],
            timeout: 600,
            output: $say,
        );
    }
}
