<?php

declare(strict_types=1);

namespace Quire\Server\Update;

/**
 * What a package whose code is new to a site (added, or at another version) may not be, read from the
 * lock Composer wrote: a Composer plugin (the site's build would run it); a stand-in for a package the
 * site has or for any of Quire's, by "replace" or "provide" (G2: it would pass for it, past D-38's
 * check by name, which is a check of what's in the lock); or, when it isn't Quire's, other than a
 * download over HTTPS naming its commit, which is what its code is known by (G3).
 */
final class PackageChecks
{
    /**
     * @param list<string> $require  What the site's composer.json requires, by name
     * @param list<string> $packages Those whose code is new, by name
     *
     * @return list<string> Why each is refused; none when they may be
     */
    public static function refusals(Lock $from, Lock $to, array $require, array $packages): array
    {
        $has = array_fill_keys([...array_keys($from->versions), ...$require], true);
        $refused = [];

        foreach ($packages as $package) {
            $details = $to->details[$package] ?? null;

            if (null === $details) {
                continue;
            }

            if ('composer-plugin' === $details['type']) {
                $refused[] = \sprintf('%s is a Composer plugin (its code would run in every build of the site)', $package);
            }

            foreach (['replace' => 'replaces', 'provide' => 'provides'] as $link => $verb) {
                $names = array_values(array_filter($details[$link], static fn (string $name): bool => $name !== $package && (isset($has[$name]) || str_starts_with($name, 'quire/'))));

                if ([] !== $names) {
                    $refused[] = \sprintf('%s %s %s (it would stand in for %s)', $package, $verb, implode(', ', $names), 1 === \count($names) ? 'it' : 'them');
                }
            }

            if (str_starts_with($package, 'quire/')) {
                continue; // Checked against Quire's signed release feed when the site is built (D-38)
            }

            if (null === $details['dist']['url'] || 1 !== preg_match('#^https://#', $details['dist']['url'])) {
                $refused[] = \sprintf('%s isn\'t a download over HTTPS', $package);
            } elseif (null === $details['dist']['reference']) {
                $refused[] = \sprintf('%s\'s download names no commit, which is what its code is known by', $package);
            }
        }

        return $refused;
    }
}
