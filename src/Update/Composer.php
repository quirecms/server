<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;

/**
 * Composer, on a copy of a site's composer.json and composer.lock: it only resolves versions and reads
 * advisories. Nothing is installed and no package's code runs here (no scripts, no plugins): the site's
 * image installs them, in Docker, where they can't touch the server. It runs in a container of its own
 * (ContainerRunner), since composer.json names the addresses Composer fetches from.
 */
final readonly class Composer
{
    private const string PACKAGE = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\z#';

    /**
     * A constraint on releases ("^1.2", "1.2.*", ">=1.0 <2.0", "v1.2.0"): no branch ("dev-main"), no
     * commit ("#abc…"), no stability flag ("@beta"), so nothing a push to a branch moves.
     */
    private const string CONSTRAINT = '#^[0-9v.*^~<>=|, -]{1,100}\z#';

    public function __construct(
        private ProcessRunner $runner,
    ) {
    }

    /**
     * Updates the lock in this folder to the newest versions its composer.json allows, with temporary
     * constraints on top (e.g. "quire/core:0.2.0", "symfony/yaml:8.1.*"): every package, or only these
     * (and what they need).
     *
     * @param list<string>                  $with
     * @param (\Closure(string): void)|null $output
     * @param list<string>                  $packages
     */
    public function update(string $directory, array $with, ?\Closure $output = null, array $packages = [], bool $allDependencies = true): void
    {
        foreach ($packages as $package) {
            $this->checkName($package);
        }

        foreach ($with as $constraint) {
            if (1 !== preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*:[0-9A-Za-z.*^~<>=|, -]+\z#', $constraint)) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a package constraint.', $constraint));
            }
        }

        $this->composer($directory, [
            // Only these packages and what they need, but not what the site itself requires: Composer's
            // "minimal changes" (--with-dependencies), or anything they need (--with-all-dependencies)
            'update', ...$packages, $allDependencies ? '--with-all-dependencies' : '--with-dependencies', '--no-install', '--no-scripts', '--no-plugins', '--no-audit', '--no-progress',
            // The site's image has the extensions (the agent doesn't); its build checks them.
            '--ignore-platform-req=ext-*',
            ...array_map(static fn (string $constraint): string => '--with='.$constraint, $with),
        ], 900, $output)->orFail('Working out the update (composer update)');
    }

    /**
     * Adds a package to this folder's composer.json, without resolving anything: "acme/reviews", or
     * with a constraint ("acme/reviews:^1.2"). Without one, Composer writes "^" and the newest stable
     * release (resolved by update() afterwards).
     *
     * @param (\Closure(string): void)|null $output
     */
    public function require(string $directory, string $package, ?string $constraint, ?\Closure $output = null): void
    {
        $this->checkName($package);

        if (null !== $constraint && 1 !== preg_match(self::CONSTRAINT, $constraint)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a version constraint, e.g. "^1.2".', $constraint));
        }

        $this->composer($directory, [
            'require', null === $constraint ? $package : $package.':'.$constraint, '--no-update', '--no-scripts', '--no-plugins', '--no-audit', '--no-progress',
            '--ignore-platform-req=ext-*',
        ], 300, $output)->orFail(\sprintf('Adding %s (composer require)', $package));
    }

    /**
     * Removes a package from this folder's composer.json and lock, with what only it needed.
     *
     * @param (\Closure(string): void)|null $output
     */
    public function remove(string $directory, string $package, ?\Closure $output = null): void
    {
        $this->checkName($package);

        $this->composer($directory, [
            'remove', $package, '--no-install', '--no-scripts', '--no-plugins', '--no-audit', '--no-progress',
            '--ignore-platform-req=ext-*',
        ], 900, $output)->orFail(\sprintf('Removing %s (composer remove)', $package));
    }

    /**
     * Why the lock in this folder has a package: Composer's own answer ("composer why"), its lines.
     *
     * @return list<string>
     */
    public function why(string $directory, string $package): array
    {
        $this->checkName($package);
        $result = $this->composer($directory, ['why', $package, '--locked', '--no-plugins'], 120);

        return array_values(array_filter(array_map(trim(...), explode("\n", $result->output)), static fn (string $line): bool => '' !== $line));
    }

    public static function isName(string $package): bool
    {
        return 1 === preg_match(self::PACKAGE, $package);
    }

    /**
     * Known vulnerabilities in the packages of this folder's lock, from Packagist's advisories.
     *
     * @return array<string, list<array{title: string, cve: ?string, link: ?string, severity: ?string}>> Package => advisories
     */
    public function audit(string $directory): array
    {
        $result = $this->composer($directory, ['audit', '--locked', '--format=json', '--no-plugins', '--abandoned=ignore'], 300);

        // Exit codes 1 (vulnerable) and 2 (abandoned) still print the report.
        try {
            $report = json_decode($result->output, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $result->orFail('Checking for known vulnerabilities (composer audit)');

            throw new \RuntimeException('composer audit printed no report.');
        }

        $advisories = [];

        foreach (\is_array($report) && \is_array($report['advisories'] ?? null) ? $report['advisories'] : [] as $package => $list) {
            foreach (\is_array($list) ? $list : [] as $advisory) {
                if (!\is_array($advisory)) {
                    continue;
                }

                $advisories[(string) $package][] = [
                    'title' => \is_string($advisory['title'] ?? null) ? $advisory['title'] : 'Advisory',
                    'cve' => \is_string($advisory['cve'] ?? null) ? $advisory['cve'] : null,
                    'link' => \is_string($advisory['link'] ?? null) ? $advisory['link'] : null,
                    'severity' => \is_string($advisory['severity'] ?? null) ? $advisory['severity'] : null,
                ];
            }
        }

        ksort($advisories);

        return $advisories;
    }

    private function checkName(string $package): void
    {
        if (!self::isName($package)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a package name, e.g. "acme/reviews".', mb_substr($package, 0, 100)));
        }
    }

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output
     */
    private function composer(string $directory, array $arguments, float $timeout, ?\Closure $output = null): ProcessResult
    {
        $this->checkSources($directory);
        $home = sys_get_temp_dir().'/composer-'.bin2hex(random_bytes(6));

        try {
            return $this->runner->run(
                ['composer', ...$arguments, '--working-dir='.$directory, '--no-interaction', '--no-ansi'],
                [
                    'COMPOSER_HOME' => $home,
                    'COMPOSER_CACHE_DIR' => $home.'/cache',
                    'COMPOSER_ALLOW_SUPERUSER' => '1',
                    // Nothing inherited chooses another file, repository or credentials, or lets through
                    // versions with known vulnerabilities (Composer refuses to resolve to those).
                    'COMPOSER' => false,
                    'COMPOSER_NO_BLOCKING' => false,
                    'COMPOSER_NO_SECURITY_BLOCKING' => false,
                    'COMPOSER_AUTH' => false,
                    'COMPOSER_VENDOR_DIR' => false,
                    'COMPOSER_BIN_DIR' => false,
                ],
                $directory,
                $timeout,
                $output,
            );
        } finally {
            new \Symfony\Component\Filesystem\Filesystem()->remove($home);
        }
    }

    /**
     * Composer fetches only over HTTPS, as it does by default, and sends nothing of a site's own (no
     * request options: headers, methods, bodies). Its container has no way to the server's networks
     * anyway; this says so plainly, before anything runs.
     */
    private function checkSources(string $directory): void
    {
        try {
            $json = json_decode((string) @file_get_contents($directory.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return; // Composer says what's wrong with it.
        }

        $config = \is_array($json) && \is_array($json['config'] ?? null) ? $json['config'] : [];
        $refused = [];

        if (false === ($config['secure-http'] ?? true)) {
            $refused[] = '"secure-http": false';
        }

        if (true === ($config['disable-tls'] ?? false)) {
            $refused[] = '"disable-tls": true';
        }

        $repositories = \is_array($json) && \is_array($json['repositories'] ?? null) ? $json['repositories'] : [];
        $walk = static function (array $value) use (&$walk, &$refused): void {
            foreach ($value as $key => $item) {
                if ('options' === $key) {
                    $refused[] = 'a repository\'s "options"';
                } elseif ('url' === $key && \is_string($item) && 1 === preg_match('#^http://#i', $item)) {
                    $refused[] = \sprintf('"%s" (plain HTTP)', $item);
                } elseif (\is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($repositories);

        if ([] !== $refused) {
            throw new \DomainException(\sprintf('The site\'s composer.json has Composer fetch other than over HTTPS: %s. The server runs Composer over HTTPS only; change the site\'s composer.json.', implode(', ', array_unique($refused))));
        }
    }
}
