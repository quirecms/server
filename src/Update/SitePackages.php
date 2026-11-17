<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Registry\SiteGit;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\SiteName;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A managed site's packages, added, updated and removed by its owner (Gap B): each is worked out on a
 * copy of the site's composer files, checked, and applied as an update is (SiteUpdater: backed up,
 * built beside, checked, rolled back by itself, undone within a week).
 *
 * What's added is a Quire extension or theme, from Packagist, installed but not enabled (the owner
 * enables it in the admin, where its settings are). Adding one changes nothing else: no package the
 * site has moves, and none of what it brings may stand in for one the site has or for Quire's
 * (replace, provide: G2), be a Composer plugin, or come with a known vulnerability. Quire's own
 * packages are checked against its signed release feed when the site is built (D-38). Anyone else's
 * is added only when asked (--third-party), shown with the commit its download names (G3), and held
 * where it is by every update after (G1): only "update" here moves it, with its checks again.
 */
final readonly class SitePackages
{
    /** What an owner adds: a feature. A library comes with the extension that needs it. */
    public const array TYPES = ['quire-extension', 'quire-theme'];

    /** Running, as quire:about says: "broken" is enabled, but it failed to start */
    private const array RUNS = ['enabled', 'broken'];

    private const string REQUIREMENT = '#^([^:\s]+)(?::(.+))?\z#';

    public function __construct(
        private Composer $composer,
        private ServerPaths $paths,
        private SiteGit $repositories,
        private SiteExtensions $extensions,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * What the site requires, and what each package is.
     *
     * @return list<array{package: string, constraint: string, version: ?string, type: ?string, added: ?string}> By name; "added": null (it came with the site), "quire" or "third-party"
     */
    public function list(SiteName $name): array
    {
        $site = $this->site($name, false);
        $json = AddedPackages::read($site.'/composer.json');
        $lock = Lock::read($site.'/composer.lock');
        $added = AddedPackages::of($json);
        $rows = [];

        foreach (\is_array($json['require'] ?? null) ? $json['require'] : [] as $package => $constraint) {
            $package = (string) $package;

            if (!Composer::isName($package)) {
                continue; // "php", "ext-intl"
            }

            $rows[] = [
                'package' => $package,
                'constraint' => \is_string($constraint) ? $constraint : '',
                'version' => $lock->versions[$package] ?? null,
                'type' => $lock->details[$package]['type'] ?? null,
                'added' => isset($added[$package]) ? ($added[$package] ? 'third-party' : 'quire') : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['package'] <=> $b['package']);

        return $rows;
    }

    /**
     * Adds a package: "quire/analytics", or with a constraint ("acme/reviews:^1.2").
     *
     * @param bool                          $thirdParty The owner allows a package that isn't Quire's
     * @param (\Closure(string): void)|null $output
     */
    public function add(SiteName $name, string $requirement, bool $thirdParty, ?\Closure $output = null): PackagePlan
    {
        [$package, $constraint] = $this->requirement($requirement);
        $site = $this->site($name, true);
        $json = AddedPackages::read($site.'/composer.json');
        $quire = self::isQuire($package);

        if (\is_array($json['require'] ?? null) && \array_key_exists($package, $json['require'])) {
            throw new \DomainException(\sprintf('"%s" has %s already%s.', $name, $package, isset(AddedPackages::of($json)[$package]) && !$quire ? \sprintf(': "site:packages %s update %s" moves it', $name, $package) : ''));
        }

        $locked = Lock::read($site.'/composer.lock')->versions[$package] ?? null;

        if (null !== $locked) {
            throw new \DomainException(\sprintf('"%s" has %s %s already, because another package needs it ("site:packages %s" lists what it has).', $name, $package, $locked, $name));
        }

        if (!$quire && !$thirdParty) {
            throw new \DomainException(\sprintf('%s isn\'t Quire\'s: its code would run in the site, with the site\'s database and files. Add it only from someone you trust, with --third-party.', $package));
        }

        return $this->planned($name, $site, function (string $workspace, Lock $from) use ($package, $constraint, $quire, $output): array {
            // Recorded before Composer resolves: the lock's content-hash covers composer.json's "extra".
            $added = AddedPackages::of(AddedPackages::read($workspace.'/composer.json'));
            $added[$package] = !$quire;
            $this->filesystem->dumpFile($workspace.'/composer.json', AddedPackages::with((string) file_get_contents($workspace.'/composer.json'), $added));
            $this->composer->require($workspace, $package, $constraint, $output);
            // Only it and what it needs: nothing the site requires moves (Composer's "minimal changes").
            $this->composer->update($workspace, [], $output, [$package], false);
            $to = Lock::read($workspace.'/composer.lock');
            $this->ensureFeature($package, $to);
            $this->ensureNothingElseMoves($from, $to, []);

            return [$to, PackagePlan::ADD, $package, !$quire];
        });
    }

    /**
     * Moves a third-party package the owner added: within its constraint, or to another one
     * ("acme/reviews:^2.0"). Quire's packages move with the site's updates ("site:update").
     *
     * @param (\Closure(string): void)|null $output
     */
    public function update(SiteName $name, string $requirement, bool $thirdParty, ?\Closure $output = null): PackagePlan
    {
        [$package, $constraint] = $this->requirement($requirement);
        $site = $this->site($name, true);
        $json = AddedPackages::read($site.'/composer.json');
        $added = AddedPackages::of($json);

        if (!isset($added[$package])) {
            throw new \DomainException(self::isQuire($package) || \is_array($json['require'] ?? null) && \array_key_exists($package, $json['require']) ? \sprintf('%s moves with the site\'s updates: "site:update %s".', $package, $name) : \sprintf('"%s" has no %s added with "site:packages" ("site:packages %s" lists what it has).', $name, $package, $name));
        }

        if (!$added[$package]) {
            throw new \DomainException(\sprintf('%s is Quire\'s: it moves with the site\'s updates ("site:update %s").', $package, $name));
        }

        if (!$thirdParty) {
            throw new \DomainException(\sprintf('%s isn\'t Quire\'s: its new code would run in the site. Move it only when you trust its new version, with --third-party.', $package));
        }

        return $this->planned($name, $site, function (string $workspace, Lock $from) use ($name, $package, $constraint, $output): array {
            $json = AddedPackages::read($workspace.'/composer.json');
            // What only it needs moves with it; what another third-party package needs stays.
            $moving = array_keys(array_diff_key(AddedPackages::held($json, $from), AddedPackages::held($json, $from, $package)));
            $held = AddedPackages::held($json, $from, $package);

            if (null !== $constraint) {
                $this->composer->require($workspace, $package, $constraint, $output);
            }

            $this->composer->update($workspace, array_map(static fn (string $name, string $version): string => $name.':'.$version, array_keys($held), $held), $output, [$package], false);
            $to = Lock::read($workspace.'/composer.lock');
            $this->ensureFeature($package, $to);
            $this->ensureNothingElseMoves($from, $to, $moving);
            $gone = $this->departures($from, $to);

            if ([] !== $gone) {
                // What its new version needs no more: not while the site runs it.
                $this->ensureNotRunning($this->extensions->of($name), $gone, $from, $name);
            }

            if (($from->versions[$package] ?? null) === ($to->versions[$package] ?? null) && [] === $from->changesTo($to)) {
                throw new \DomainException(\sprintf('%s %s is the newest its constraint allows%s.', $package, $from->versions[$package] ?? '?', null === $constraint ? \sprintf(' ("site:packages … update %s:<constraint>" gives it another)', $package) : ''));
            }

            return [$to, PackagePlan::UPDATE, $package, true];
        });
    }

    /**
     * Removes a package the owner added: refused while the site runs it (an enabled extension, its
     * theme) or another package needs it.
     *
     * @param (\Closure(string): void)|null $output
     */
    public function remove(SiteName $name, string $package, ?\Closure $output = null): PackagePlan
    {
        [$package] = $this->requirement($package);
        $site = $this->site($name, true);
        $json = AddedPackages::read($site.'/composer.json');
        $added = AddedPackages::of($json);

        if (!isset($added[$package])) {
            throw new \DomainException(\is_array($json['require'] ?? null) && \array_key_exists($package, $json['require']) ? \sprintf('%s came with the site (from Quire\'s skeleton): it isn\'t removed with "site:packages".', $package) : \sprintf('"%s" has no %s added with "site:packages" ("site:packages %s" lists what it has).', $name, $package, $name));
        }

        $report = $this->extensions->of($name);
        // Before anything runs; and what goes with it once that's known.
        $this->ensureNotRunning($report, [$package], Lock::read($site.'/composer.lock'), $name);

        return $this->planned($name, $site, function (string $workspace, Lock $from) use ($name, $package, $added, $report, $output): array {
            $thirdParty = $added[$package];
            unset($added[$package]);
            $this->filesystem->dumpFile($workspace.'/composer.json', AddedPackages::with((string) file_get_contents($workspace.'/composer.json'), $added));
            $this->composer->remove($workspace, $package, $output);
            $to = Lock::read($workspace.'/composer.lock');

            if (isset($to->versions[$package])) {
                throw new \DomainException(\sprintf("Another package needs %s, so it stays:\n%s", $package, implode("\n", $this->composer->why($workspace, $package))));
            }

            // What only it needed goes with it, unless the site runs it; nothing changes version.
            $this->ensureNothingElseMoves($from, $to, [], true);
            $this->ensureNotRunning($report, $this->departures($from, $to), $from, $name);

            return [$to, PackagePlan::REMOVE, $package, $thirdParty];
        });
    }

    /**
     * @param \Closure(string, Lock): array{Lock, string, string, bool} $change Changes the copy's composer files, and checks what's particular to it
     */
    private function planned(SiteName $name, string $site, \Closure $change): PackagePlan
    {
        $workspace = sys_get_temp_dir().'/quire-packages-'.$name->value.'-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($workspace, 0o700);

        try {
            $this->filesystem->copy($site.'/composer.json', $workspace.'/composer.json');
            $this->filesystem->copy($site.'/composer.lock', $workspace.'/composer.lock');
            // G4: the files this plan is made from, as they were copied; the apply refuses any others.
            $siteFiles = UpdatePlanner::siteFiles($workspace);
            $from = Lock::read($workspace.'/composer.lock');
            $advisoriesBefore = $this->composer->audit($workspace);
            [$to, $action, $package, $thirdParty] = $change($workspace, $from);
            $advisoriesAfter = $this->composer->audit($workspace);
            // G2, G3 and no plugins: over every package whose code is new, arrived or moved (G2′)
            $arrived = $this->arrivals($from, $to);
            $json = AddedPackages::read($workspace.'/composer.json');
            $refused = PackageChecks::refusals($from, $to, \is_array($json['require'] ?? null) ? array_map(strval(...), array_keys($json['require'])) : [], $arrived);

            if ([] !== $refused) {
                throw new \DomainException(\sprintf('%s: %s.', match ($action) {
                    PackagePlan::ADD => 'It can\'t be added', PackagePlan::UPDATE => 'It can\'t be moved', default => 'It can\'t be removed',
                }, implode('; ', $refused)));
            }

            $this->ensureNoNewAdvisories($advisoriesBefore, $advisoriesAfter);
            $composerJson = (string) file_get_contents($workspace.'/composer.json');
            $version = $to->versions[$package] ?? null;
            $changes = $from->changesTo($to);

            $plan = new UpdatePlan(
                $name, $workspace, $from, $to, $composerJson, $changes, [], null, true, $advisoriesBefore, $advisoriesAfter, $siteFiles,
                AddedPackages::held(AddedPackages::read($workspace.'/composer.json'), $to),
                'site.packages',
                ['action' => $action, 'package' => $package, 'from' => $from->versions[$package] ?? null, 'to' => $version, 'third_party' => $thirdParty, 'packages' => \count($changes)],
            );

            return new PackagePlan($plan, $action, $package, $thirdParty, $version, array_map(static fn (string $arrival): array => [
                'package' => $arrival,
                'version' => $to->versions[$arrival],
                'type' => $to->details[$arrival]['type'],
                'quire' => self::isQuire($arrival),
                'source' => $to->details[$arrival]['source']['url'],
                'download' => $to->details[$arrival]['dist']['url'],
                'reference' => $to->details[$arrival]['dist']['reference'] ?? $to->details[$arrival]['source']['reference'],
                'from' => $from->versions[$arrival] ?? null,
            ], $arrived));
        } catch (\Throwable $e) {
            $this->filesystem->remove($workspace);

            throw $e;
        }
    }

    /**
     * The site's folder, its composer files there; one that follows a Git repository gets its packages
     * there, and one whose composer.json names other repositories than Packagist gets none here.
     */
    private function site(SiteName $name, bool $changing): string
    {
        $site = $this->paths->site($name);

        foreach (['composer.json', 'composer.lock'] as $file) {
            if (!is_file($site.'/'.$file)) {
                throw new \DomainException(\sprintf('"%s" has no %s (is it a site of this server? "quire-server site:list").', $name, $file));
            }
        }

        if (!$changing) {
            return $site;
        }

        if (null !== $this->repositories->of($name)) {
            throw new \DomainException(\sprintf('"%s" follows a Git repository: add its packages there (composer require, committed), then deploy it with "site:deploy".', $name));
        }

        $json = AddedPackages::read($site.'/composer.json');

        if (\array_key_exists('repositories', $json) && [] !== $json['repositories']) {
            throw new \DomainException(\sprintf('"%s"\'s composer.json names repositories of its own: packages come from Packagist only here (private and VCS repositories aren\'t supported yet).', $name));
        }

        return $site;
    }

    /**
     * @return array{string, ?string} The package and its constraint
     */
    private function requirement(string $requirement): array
    {
        if (1 !== preg_match(self::REQUIREMENT, $requirement, $match) || !Composer::isName($match[1])) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a package, e.g. "quire/analytics" or "acme/reviews:^1.2".', mb_substr($requirement, 0, 100)));
        }

        return [$match[1], $match[2] ?? null];
    }

    private static function isQuire(string $package): bool
    {
        return str_starts_with($package, 'quire/');
    }

    /**
     * What an owner adds is a feature: a Quire extension or theme.
     */
    private function ensureFeature(string $package, Lock $to): void
    {
        $type = $to->details[$package]['type'] ?? null;

        if (null === $type) {
            throw new \RuntimeException(\sprintf('Composer didn\'t add %s.', $package));
        }

        if (!\in_array($type, self::TYPES, true)) {
            throw new \DomainException(\sprintf('%s is a "%s", not a Quire extension or theme: only those are added (a library comes with the extension that needs it).', $package, $type));
        }
    }

    /**
     * No package the site has moves or goes, but these (and, $mayGo, any that goes).
     *
     * @param list<string> $mayMove
     */
    private function ensureNothingElseMoves(Lock $from, Lock $to, array $mayMove, bool $mayGo = false): void
    {
        $moved = array_filter($from->changesTo($to), static fn (array $change): bool => null !== $change['from'] && !\in_array($change['package'], $mayMove, true) && (!$mayGo || null !== $change['to']));

        if ([] !== $moved) {
            throw new \DomainException(\sprintf('It would move packages the site has: %s. Nothing else moves here: update the site first ("site:update"), then try again.', implode(', ', array_map(static fn (array $change): string => \sprintf('%s %s → %s', $change['package'], $change['from'], $change['to'] ?? '(removed)'), $moved))));
        }
    }

    /**
     * @return list<string> The packages whose code the new lock has and the old one doesn't: those it adds, and those whose version it changes, by name
     */
    private function arrivals(Lock $from, Lock $to): array
    {
        return array_values(array_map(static fn (array $change): string => $change['package'], array_filter($from->changesTo($to), static fn (array $change): bool => null !== $change['to'])));
    }

    /**
     * @param array<string, list<array{title: string, cve: ?string, link: ?string, severity: ?string}>> $before
     * @param array<string, list<array{title: string, cve: ?string, link: ?string, severity: ?string}>> $after
     */
    private function ensureNoNewAdvisories(array $before, array $after): void
    {
        $new = [];

        foreach ($after as $package => $advisories) {
            $known = array_column($before[$package] ?? [], 'title');

            foreach ($advisories as $advisory) {
                if (!\in_array($advisory['title'], $known, true)) {
                    $new[] = \sprintf('%s: %s%s', $package, $advisory['title'], null === $advisory['cve'] ? '' : ' ('.$advisory['cve'].')');
                }
            }
        }

        if ([] !== $new) {
            throw new \DomainException(\sprintf('It would bring known vulnerabilities: %s.', implode('; ', $new)));
        }
    }

    /**
     * @return list<string> The packages the old lock has that the new one doesn't, by name
     */
    private function departures(Lock $from, Lock $to): array
    {
        return array_values(array_map(static fn (array $change): string => $change['package'], array_filter($from->changesTo($to), static fn (array $change): bool => null === $change['to'])));
    }

    /**
     * Refuses taking away a package the site runs: an enabled extension (or one that failed to start),
     * its theme, or the parent of its theme (or of that one's parent).
     *
     * @param array{extensions: list<array{id: string, package: string, status: string}>, theme: ?string} $report   What the site runs
     * @param list<string>                                                                                $packages
     */
    private function ensureNotRunning(array $report, array $packages, Lock $lock, SiteName $name): void
    {
        foreach ($report['extensions'] as $extension) {
            if (\in_array($extension['package'], $packages, true) && \in_array($extension['status'], self::RUNS, true)) {
                throw new \DomainException(\sprintf('"%s" runs %s ("%s", %s): disable it in its admin first (Extensions).', $name, $extension['package'], $extension['id'], $extension['status']));
            }
        }

        // quire:about names only the active theme: its parents are in the lock.
        $themes = [];

        foreach ($lock->details as $locked => $details) {
            if ('quire-theme' === $details['type']) {
                $themes[$details['id']] = [$locked, $details['parent']];
            }
        }

        $theme = $report['theme'];
        $seen = [];

        while (null !== $theme && isset($themes[$theme]) && !isset($seen[$theme])) {
            $seen[$theme] = true;
            $package = $themes[$theme][0];

            if (\in_array($package, $packages, true)) {
                throw new \DomainException($theme === $report['theme'] ? \sprintf('%s is "%s"\'s theme: choose another in its admin first (Themes).', $package, $name) : \sprintf('%s is the parent of "%s"\'s theme (%s): choose another in its admin first (Themes).', $package, $name, $report['theme']));
            }

            $theme = $themes[$theme][1];
        }
    }
}
