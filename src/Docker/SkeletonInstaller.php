<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Process\ProcessRunner;

/**
 * Creates a site's folder from quire/skeleton, with Composer, without running any of its code: no
 * scripts, no plugins, no dependencies installed (they're installed inside the site's image build,
 * in Docker, where they can't touch the server).
 */
final readonly class SkeletonInstaller
{
    /** "^0.1", "~1.2.3", "0.2.0", "1.0.0-beta2" or "dev-main" */
    private const string VERSION = '/^(?:[\^~]?\d+\.\d+(?:\.\d+)?(?:-[a-z0-9.]+)?|dev-main)\z/';

    public function __construct(
        private ProcessRunner $runner,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $output
     */
    public function create(string $directory, string $version, ?\Closure $output = null): void
    {
        if (1 !== preg_match(self::VERSION, $version)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a Quire version, e.g. "^0.1" or "0.2.0".', $version));
        }

        if (file_exists($directory)) {
            throw new \DomainException(\sprintf('"%s" exists already.', $directory));
        }

        $home = sys_get_temp_dir().'/composer-'.bin2hex(random_bytes(6));

        try {
            $this->runner->run(
                ['composer', 'create-project', 'quire/skeleton', $directory, $version, '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--no-ansi'],
                ['COMPOSER_HOME' => $home, 'COMPOSER_CACHE_DIR' => $home.'/cache', 'COMPOSER_NO_AUDIT' => '1'],
                timeout: 600,
                output: $output,
            )->orFail('Getting the site\'s files (composer create-project quire/skeleton)');
        } finally {
            new \Symfony\Component\Filesystem\Filesystem()->remove($home);
        }

        chmod($directory, 0o750);
    }
}
