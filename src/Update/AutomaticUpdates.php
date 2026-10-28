<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\UpdatePolicies;
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
 * recorded as asked by "automatic". Never without a person: an update that changes the skeleton's
 * files the site edited, or Quire's version without the signed release feed vouching for it (or
 * with it saying this server can't run it). Git mode sites never: their updates are made in their
 * repository.
 */
final readonly class AutomaticUpdates
{
    public const string REQUESTER = 'automatic';

    public const string UPDATED = 'updated';
    public const string UP_TO_DATE = 'up to date';
    public const string SKIPPED = 'skipped';
    public const string FAILED = 'failed';

    public function __construct(
        private UpdatePolicies $policies,
        private SiteRegistry $registry,
        private UpdatePlanner $planner,
        private SiteUpdater $updater,
        private ReleaseCheck $releases,
        private string $hour = '3',
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
            $sites = array_values(array_filter($this->policies->updatable(), fn (SiteName $site): bool => UpdatePolicies::OFF !== $this->policies->of($site)));
        } elseif (null !== $hour) {
            $sites = $this->policies->due($hour);
        } else {
            return [];
        }

        $outcomes = [];

        foreach ($sites as $site) {
            // Before: a site whose update fails isn't tried again every minute, only the next night.
            $this->policies->checked($site);
            [$outcome, $detail] = $this->update($site, $this->policies->of($site), $channel, static fn (string $step) => $say(\sprintf('%s: %s', $site, $step)));
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
        $skip = self::reasonToSkip($plan, $policy, $registered instanceof \Quire\Server\Site\Site ? $this->releases->check($plan, $registered) : null);

        if (null !== $skip) {
            $plan->discard();

            return [$plan->changesAnything() ? self::SKIPPED : self::UP_TO_DATE, $skip];
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
    public static function parseHour(string $hour): ?int
    {
        if ('off' === $hour) {
            return null;
        }

        if (1 !== preg_match('/^(?:[0-9]|1[0-9]|2[0-3])\z/', $hour)) {
            throw new \InvalidArgumentException(\sprintf('QUIRE_SERVER_UPDATE_HOUR is "%s": an hour from 0 to 23 (UTC), or "off".', SiteName::printable($hour)));
        }

        return (int) $hour;
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
            !$plan->changesAnything() => 'nothing to update',
            UpdatePolicies::OFF === $policy => 'its automatic updates are off',
            UpdatePolicies::SECURITY === $policy && [] === $plan->fixedAdvisories() => 'no update fixes its known vulnerabilities yet',
            [] !== $plan->conflicts() => 'it edited files of the skeleton the new version changes: "site:update" shows them',
            null === $check => 'it isn\'t in the registry',
            [] !== $check['blocked'] => implode(' ', $check['blocked']),
            !$check['vouched'] => \sprintf('Quire %s isn\'t vouched for by the signed release feed: "site:update" makes it by hand', $plan->quireTo()),
            default => null,
        };
    }
}
