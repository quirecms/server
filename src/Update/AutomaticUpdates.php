<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Docker\RuntimeImages;
use Quire\Server\Notify\Checks;
use Quire\Server\Registry\RuntimeBuilds;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\UpdatePolicies;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;

/**
 * The updates sites get by themselves, every night from the hour set (QUIRE_SERVER_UPDATE_HOUR, UTC;
 * "off" for none on this server), as each site's policy says:
 *
 * - "security" (the default): only when a package it runs has a known vulnerability, and then only
 *   that package (and what it needs), and only when the update fixes it;
 * - "all": everything its composer.json allows;
 * - "off": none.
 *
 * Each goes as a manual update does (built beside, backed up, checked, rolled back by itself), and is
 * recorded as asked by "automatic". A site that wasn't just updated is built again on Quire's runtime
 * images when they have a newer (checked) build than the one it was built on: their security fixes
 * reach it, Git mode sites' too. Never without a person: an update that changes the skeleton's
 * files the site edited, or Quire's version without the signed release feed vouching for it (or
 * with it saying this server can't run it). Git mode sites get no updates: they're made in their
 * repository.
 */
final readonly class AutomaticUpdates
{
    public const string REQUESTER = 'automatic';

    public const string UPDATED = 'updated';
    public const string REBUILT = 'rebuilt';
    public const string UP_TO_DATE = 'up to date';
    public const string SKIPPED = 'skipped';
    public const string FAILED = 'failed';

    /** The check that says a site's update waits for a person: a package re-published under its version */
    public const string REPUBLISHED_CHECK = 'updates.republished.';

    public function __construct(
        private UpdatePolicies $policies,
        private SiteRegistry $registry,
        private UpdatePlanner $planner,
        private SiteUpdater $updater,
        private ReleaseCheck $releases,
        private SiteGit $repositories,
        private RuntimeImages $runtime,
        private RuntimeBuilds $builds,
        private ServerPaths $paths,
        private string $hour = '3',
        private ?Checks $checks = null,
    ) {
    }

    /**
     * The sites due tonight (or, $now, every site that gets automatic updates), one after the other.
     *
     * @param \Closure(string): void $say
     *
     * @return list<array{site: string, outcome: string, detail: string}>
     */
    public function run(bool $now, string $channel, \Closure $say): array
    {
        $hour = $this->hour();

        if ($now) {
            $sites = $this->policies->automatic();
        } elseif (null !== $hour) {
            $sites = $this->policies->due($hour);
        } else {
            return [];
        }

        $outcomes = [];

        foreach ($sites as $site) {
            // Before: a site whose update fails isn't tried again every minute, only the next night.
            $this->policies->checked($site);
            $steps = static fn (string $step) => $say(\sprintf('%s: %s', $site, $step));
            // Git mode sites' updates are made in their repository: they're only built on a newer runtime.
            [$outcome, $detail] = null === $this->repositories->of($site)
                ? $this->update($site, $this->policies->of($site), $channel, $steps)
                : [self::UP_TO_DATE, 'updated in its repository'];

            // Not just built: on a newer runtime, if there is one.
            if (self::UPDATED !== $outcome && self::FAILED !== $outcome) {
                [$outcome, $detail] = $this->refresh($site, $channel, $steps) ?? [$outcome, $detail];
            }

            $say(\sprintf('%s: %s%s', $site, $outcome, '' === $detail ? '' : ' ('.$detail.')'));
            $outcomes[] = ['site' => $site->value, 'outcome' => $outcome, 'detail' => $detail];
        }

        return $outcomes;
    }

    /**
     * @param \Closure(string): void $say
     *
     * @return array{string, string} The outcome, and why
     */
    private function update(SiteName $site, string $policy, string $channel, \Closure $say): array
    {
        try {
            $this->updater->prepare($site);
            $only = [];

            if (UpdatePolicies::SECURITY === $policy) {
                $only = array_keys($this->planner->audit($site));

                if ([] === $only) {
                    return [self::UP_TO_DATE, 'no known vulnerability'];
                }
            }

            $plan = $this->planner->plan($site, only: $only);
        } catch (\Throwable $e) {
            return [self::FAILED, 'working out the update: '.$e->getMessage()];
        }

        $registered = $this->registry->get($site);
        $skip = self::reasonToSkip($plan, $policy, $registered instanceof Site ? $this->releases->check($plan, $registered) : null);
        // Code changed under its version needs a person: told until an update takes it, or the tag comes back
        $republished = self::republishedProblem($plan);

        if ($this->checks instanceof Checks) {
            self::noteRepublished($this->checks, $plan);
        }

        if (null !== $skip) {
            $plan->discard();

            return [$plan->changesAnything() || null !== $republished ? self::SKIPPED : self::UP_TO_DATE, $skip];
        }

        try {
            $this->updater->update($plan, false, $channel, self::REQUESTER, $say);
        } catch (UpdateFailed $e) {
            return [self::FAILED, ($e->restored ? 'rolled back: ' : 'needs you: ').$e->getMessage()];
        } catch (\Throwable $e) {
            return [self::FAILED, $e->getMessage()];
        }

        $fixed = array_keys($plan->fixedAdvisories());

        return [self::UPDATED, [] === $fixed ? \sprintf('%d package(s)', \count($plan->packages)) : 'fixes '.implode(', ', $fixed)];
    }

    /**
     * Whether a site's image must be built again: its Dockerfile names Quire's runtime images, and
     * their newest checked builds aren't the ones it was built on (or that isn't known).
     *
     * @param array<string, string>      $newest By build argument
     * @param array<string, string>|null $built
     */
    public static function needsRebuild(array $newest, ?array $built): bool
    {
        if ([] === $newest) {
            return false;
        }

        ksort($newest);

        if (null !== $built) {
            ksort($built);
        }

        return $newest !== $built;
    }

    /**
     * The site built again when a newer runtime came: null when there's none.
     *
     * @param \Closure(string): void $say
     *
     * @return array{string, string}|null
     */
    private function refresh(SiteName $name, string $channel, \Closure $say): ?array
    {
        $site = $this->registry->get($name);

        if (!$site instanceof Site) {
            return null;
        }

        try {
            $newest = $this->runtime->pinned($this->paths->site($name), static function (): void {});

            if (!self::needsRebuild($newest, $this->builds->of($site->project))) {
                return null;
            }

            $this->updater->rebuild($name, 'Quire\'s runtime has a newer build', $channel, self::REQUESTER, $say);
        } catch (UpdateFailed $e) {
            return [self::FAILED, ($e->restored ? 'rebuilding on the newer runtime rolled back: ' : 'rebuilding on the newer runtime needs you: ').$e->getMessage()];
        } catch (\Throwable $e) {
            return [self::FAILED, 'its runtime: '.$e->getMessage()];
        }

        return [self::REBUILT, 'on Quire\'s newer runtime'];
    }

    /**
     * The hour (UTC) from which sites get their updates each night, or null when they get none.
     *
     * @throws \InvalidArgumentException When QUIRE_SERVER_UPDATE_HOUR is neither
     */
    public function hour(): ?int
    {
        return self::parseHour($this->hour);
    }

    /**
     * @throws \InvalidArgumentException When it's neither an hour from 0 to 23 nor "off"
     */
    public static function parseHour(string $hour, string $setting = 'QUIRE_SERVER_UPDATE_HOUR'): ?int
    {
        if ('off' === $hour) {
            return null;
        }

        if (1 !== preg_match('/^(?:[0-9]|1[0-9]|2[0-3])\z/', $hour)) {
            throw new \InvalidArgumentException(\sprintf('%s is "%s": an hour from 0 to 23 (UTC), or "off".', $setting, SiteName::printable($hour)));
        }

        return (int) $hour;
    }

    /**
     * What to tell when an update would take a package re-published under its version (its tag moved):
     * null when it wouldn't.
     *
     * @return array{string, string}|null Its summary and its advice
     */
    public static function republishedProblem(UpdatePlan $plan): ?array
    {
        if ([] === $plan->republished()) {
            return null;
        }

        // Fail-closed: what the update would fix waits with it
        $fixes = array_keys($plan->fixedAdvisories());

        return [
            \sprintf('The automatic update of %s waits for you: %s%s.', $plan->site, self::republishedText($plan), [] === $fixes ? '' : \sprintf(', and a security fix for %s waits with it', implode(', ', $fixes))),
            \sprintf('A version is meant to be fixed code: one re-published may be a maintainer\'s quiet fix, or other code under a trusted version. Look at the commits, then "quire-server site:update %s --accept-republished" takes it; until then, nothing of the site moves by itself.', $plan->site),
        ];
    }

    /**
     * Records that a site's update waits for a person (a package re-published under its version), or
     * forgets it once its plan has none.
     */
    public static function noteRepublished(Checks $checks, UpdatePlan $plan): void
    {
        $problem = self::republishedProblem($plan);
        $check = self::REPUBLISHED_CHECK.$plan->site->value;

        if (null !== $problem) {
            $checks->record($check, true, $problem[0], $problem[1]);
        } elseif (null !== $checks->get($check)) {
            $checks->forget($check);
        }
    }

    private static function republishedText(UpdatePlan $plan): string
    {
        return implode('; ', array_map(static fn (array $change): string => \sprintf('%s %s was re-published as other code (commit %s, was %s)', $change['package'], $change['version'], $change['to'] ?? '?', $change['from'] ?? '?'), $plan->republished()));
    }

    /**
     * Why an automatic update mustn't be made (or null: it may), from its plan, the site's policy and
     * what the release feed says of it (null when the site isn't in the registry).
     *
     * @param array{vouched: bool, blocked: list<string>, notes: list<string>, security: list<string>}|null $check
     */
    public static function reasonToSkip(UpdatePlan $plan, string $policy, ?array $check): ?string
    {
        return match (true) {
            // Before anything else: other code under a version nobody changed is for a person to look at.
            [] !== $plan->republished() => \sprintf('%s: "site:update %s" shows it, and --accept-republished takes it once you\'ve looked', self::republishedText($plan), $plan->site),
            !$plan->changesAnything() => 'nothing to update',
            UpdatePolicies::OFF === $policy => 'its automatic updates are off',
            UpdatePolicies::SECURITY === $policy && [] === $plan->fixedAdvisories() && [] !== array_intersect_key($plan->advisoriesBefore, $plan->held) => \sprintf('a third-party package it added has a known vulnerability (%s): those move only with "site:packages %s update <package> --third-party"', implode(', ', array_keys(array_intersect_key($plan->advisoriesBefore, $plan->held))), $plan->site),
            UpdatePolicies::SECURITY === $policy && [] === $plan->fixedAdvisories() => 'no update fixes its known vulnerabilities yet',
            [] !== $plan->conflicts() => 'it edited files of the skeleton the new version changes: "site:update" shows them',
            null === $check => 'it isn\'t in the registry',
            [] !== $check['blocked'] => implode(' ', $check['blocked']),
            !$check['vouched'] => \sprintf('Quire %s isn\'t vouched for by the signed release feed: "site:update" makes it by hand', $plan->quireTo()),
            default => null,
        };
    }
}
