<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;

/**
 * Composer, in the agent, on a copy of a site's composer.json and composer.lock: it only resolves
 * versions and reads advisories. Nothing is installed and no package's code runs here (no scripts,
 * no plugins): the site's image installs them, in Docker, where they can't touch the server.
 */
final readonly class Composer
{
    public function __construct(
        private ProcessRunner $runner,
    ) {
    }

    /**
     * Updates the lock in this folder to the newest versions its composer.json allows, with temporary
     * constraints on top (e.g. "quire/core:0.2.0", "symfony/yaml:8.1.*").
     *
     * @param list<string>                  $with
     * @param (\Closure(string): void)|null $output
     */
    public function update(string $directory, array $with, ?\Closure $output = null): void
    {
        foreach ($with as $constraint) {
            if (1 !== preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*:[0-9A-Za-z.*^~<>=|, -]+\z#', $constraint)) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a package constraint.', $constraint));
            }
        }

        $this->composer($directory, [
            'update', '--with-all-dependencies', '--no-install', '--no-scripts', '--no-plugins', '--no-audit', '--no-progress',
            // The site's image has the extensions (the agent doesn't); its build checks them.
            '--ignore-platform-req=ext-*',
            ...array_map(static fn (string $constraint): string => '--with='.$constraint, $with),
        ], 900, $output)->orFail('Working out the update (composer update)');
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

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output
     */
    private function composer(string $directory, array $arguments, float $timeout, ?\Closure $output = null): ProcessResult
    {
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
}
