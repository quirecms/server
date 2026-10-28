<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Docker\SkeletonInstaller;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\SiteName;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Works out an update: on a copy of the site's composer files, never the site's own.
 */
final readonly class UpdatePlanner
{
    /** A release to update to: "0.2.0", "1.5.0-rc1" */
    private const string VERSION = '/^\d+\.\d+\.\d+(?:-[a-z0-9.]+)?\z/';

    public function __construct(
        private Composer $composer,
        private SkeletonInstaller $skeletons,
        private ServerPaths $paths,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param string|null                   $to     A Quire release to move to (e.g. a new minor version); within its composer.json's constraints otherwise
     * @param (\Closure(string): void)|null $output
     * @param list<string>                  $only   Only these packages (and what they need), e.g. those with known vulnerabilities
     */
    public function plan(SiteName $name, ?string $to = null, ?\Closure $output = null, array $only = []): UpdatePlan
    {
        if (null !== $to && 1 !== preg_match(self::VERSION, $to)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a Quire release, e.g. "0.2.0".', $to));
        }

        $site = $this->paths->site($name);

        foreach (['composer.json', 'composer.lock'] as $file) {
            if (!is_file($site.'/'.$file)) {
                throw new \DomainException(\sprintf('The site has no %s.', $file));
            }
        }

        $workspace = sys_get_temp_dir().'/quire-update-'.$name->value.'-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($workspace.'/composer', 0o700);

        try {
            $this->filesystem->copy($site.'/composer.json', $workspace.'/composer/composer.json');
            $this->filesystem->copy($site.'/composer.lock', $workspace.'/composer/composer.lock');
            $from = Lock::read($workspace.'/composer/composer.lock');
            $advisoriesBefore = $this->composer->audit($workspace.'/composer');
            $with = [...$this->moveTo($workspace.'/composer/composer.json', $to), ...$this->symfonyMinor($workspace.'/composer/composer.json', $from)];

            $this->composer->update($workspace.'/composer', $with, $output, $only);

            $toLock = Lock::read($workspace.'/composer/composer.lock');
            $composerJson = (string) file_get_contents($workspace.'/composer/composer.json');
            $advisoriesAfter = $this->composer->audit($workspace.'/composer');

            $skeletonFiles = [];
            $skeletonTarget = null;
            $baseKnown = true;
            $fromQuire = $this->release($from->quire());
            $toQuire = $this->release($toLock->quire());

            // A new Quire: the skeleton's files of its release, compared with those the site came from.
            if (null !== $toQuire && $fromQuire !== $toQuire) {
                $skeletonTarget = $workspace.'/skeleton-new';
                $this->skeletons->create($skeletonTarget, $toQuire, $output);
                $base = null;

                if (null !== $fromQuire) {
                    try {
                        $this->skeletons->create($workspace.'/skeleton-old', $fromQuire, $output);
                        $base = $workspace.'/skeleton-old';
                    } catch (\RuntimeException) {
                        // Not on Packagist: every difference with the site's files is shown as a conflict.
                    }
                }

                $baseKnown = null !== $base;
                $skeletonFiles = SkeletonFiles::changes($site, $base, $skeletonTarget);
            }

            return new UpdatePlan($name, $workspace, $from, $toLock, $composerJson, $from->changesTo($toLock), $skeletonFiles, $skeletonTarget, $baseKnown, $advisoriesBefore, $advisoriesAfter, self::siteFiles($site));
        } catch (\Throwable $e) {
            $this->filesystem->remove($workspace);

            throw $e;
        }
    }

    /**
     * Known vulnerabilities in the packages the site runs (its composer.lock).
     *
     * @return array<string, list<array{title: string, cve: ?string, link: ?string, severity: ?string}>>
     */
    public function audit(SiteName $name): array
    {
        $site = $this->paths->site($name);

        if (!is_file($site.'/composer.lock')) {
            throw new \DomainException('The site has no composer.lock.');
        }

        $workspace = sys_get_temp_dir().'/quire-audit-'.$name->value.'-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($workspace, 0o700);

        try {
            $this->filesystem->copy($site.'/composer.json', $workspace.'/composer.json');
            $this->filesystem->copy($site.'/composer.lock', $workspace.'/composer.lock');

            return $this->composer->audit($workspace);
        } finally {
            $this->filesystem->remove($workspace);
        }
    }

    /**
     * A hash of the site's composer files: the plan holds while it's the same.
     */
    public static function siteFiles(string $site): string
    {
        return hash('sha256', (@file_get_contents($site.'/composer.json'))."\0".(@file_get_contents($site.'/composer.lock')));
    }

    /**
     * To another release: every quire/* package the site requires, at that release ("^0.2.0" kept in
     * composer.json, exactly 0.2.0 for this update).
     *
     * @return list<string> Temporary constraints
     */
    private function moveTo(string $composerJson, ?string $to): array
    {
        if (null === $to) {
            return [];
        }

        $json = $this->json($composerJson);
        $with = [];
        $require = \is_array($json['require'] ?? null) ? $json['require'] : [];

        foreach (array_keys($require) as $package) {
            if (str_starts_with((string) $package, 'quire/')) {
                $require[$package] = '^'.$to;
                $with[] = $package.':'.$to;
            }
        }

        if ([] === $with) {
            throw new \DomainException('The site requires no quire/* package.');
        }

        $json['require'] = $require;
        file_put_contents($composerJson, json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");

        return $with;
    }

    /**
     * Symfony stays on the minor version the site is on (extra.symfony.require, e.g. "8.1.*"), as
     * Symfony Flex would keep it: Flex is a plugin, and plugins don't run here.
     *
     * @return list<string> Temporary constraints
     */
    private function symfonyMinor(string $composerJson, Lock $lock): array
    {
        $json = $this->json($composerJson);
        $require = \is_array($json['extra'] ?? null) && \is_array($json['extra']['symfony'] ?? null) ? ($json['extra']['symfony']['require'] ?? null) : null;

        if (!\is_string($require) || 1 !== preg_match('/^(\d+\.\d+)\.\*\z/', $require, $match)) {
            return [];
        }

        $with = [];

        foreach ($lock->versions as $package => $version) {
            if (str_starts_with($package, 'symfony/') && str_starts_with(ltrim($version, 'v'), $match[1].'.')) {
                $with[] = $package.':'.$require;
            }
        }

        return $with;
    }

    /**
     * @return array<mixed>
     */
    private function json(string $path): array
    {
        try {
            $json = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The site\'s composer.json isn\'t JSON.', $e->getCode(), previous: $e);
        }

        return \is_array($json) ? $json : throw new \RuntimeException('The site\'s composer.json isn\'t an object.');
    }

    /**
     * "v0.1.0" → "0.1.0"; null for a branch ("dev-main").
     */
    private function release(?string $version): ?string
    {
        if (null === $version) {
            return null;
        }

        $version = ltrim($version, 'v');

        return 1 === preg_match(self::VERSION, $version) ? $version : null;
    }
}
