<?php

declare(strict_types=1);

namespace Quire\Server\Update;

/**
 * The packages an owner added to a managed site with "site:packages", as its composer.json records them
 * (extra.quire-server.packages), whether each is a third party's:
 *
 *   "extra": {"quire-server": {"packages": {"acme/reviews": {"third-party": true}, "quire/analytics": {"third-party": false}}}}
 *
 * The record is with the lock, so a backup, a rollback and an undo bring it back with the packages
 * themselves; and it's written before Composer resolves, so the lock's content-hash covers it.
 *
 * A third-party package, and every package only it needs, never moves in an update (G1): only
 * "site:packages <site> update <package>" moves them, with its plan, its checks and a person's yes.
 * Quire's own packages follow the site's updates like the rest.
 */
final class AddedPackages
{
    private const string KEY = 'quire-server';

    /**
     * @param array<mixed> $composerJson
     *
     * @return array<string, bool> Package => whether it's a third party's, by name
     */
    public static function of(array $composerJson): array
    {
        $extra = \is_array($composerJson['extra'] ?? null) ? $composerJson['extra'] : [];
        $ours = \is_array($extra[self::KEY] ?? null) ? $extra[self::KEY] : [];
        $added = [];

        foreach (\is_array($ours['packages'] ?? null) ? $ours['packages'] : [] as $package => $entry) {
            if (\is_string($package) && Composer::isName($package)) {
                // Quire's only when it's Quire's name and marked so: a damaged record holds a package where it is.
                $added[$package] = !str_starts_with($package, 'quire/') || !\is_array($entry) || false !== ($entry['third-party'] ?? true);
            }
        }

        ksort($added);

        return $added;
    }

    /**
     * A composer.json with this record instead of the one it has, changed nowhere else (its objects
     * stay objects, even empty ones, which PHP's arrays would turn into lists).
     *
     * @param array<string, bool> $added Package => whether it's a third party's
     */
    public static function with(string $composerJson, array $added): string
    {
        try {
            $json = json_decode($composerJson, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The site\'s composer.json isn\'t JSON.', $e->getCode(), previous: $e);
        }

        if (!$json instanceof \stdClass) {
            throw new \RuntimeException('The site\'s composer.json isn\'t an object.');
        }

        ksort($added);
        $packages = new \stdClass();

        foreach ($added as $package => $thirdParty) {
            $packages->{$package} = (object) ['third-party' => $thirdParty];
        }

        $extra = $json->extra ?? null;
        $extra = $extra instanceof \stdClass ? $extra : new \stdClass();
        $ours = $extra->{self::KEY} ?? null;
        $ours = $ours instanceof \stdClass ? $ours : new \stdClass();

        if ([] === $added) {
            unset($ours->packages);
        } else {
            $ours->packages = $packages;
        }

        if ([] === get_object_vars($ours)) {
            unset($extra->{self::KEY});
        } else {
            $extra->{self::KEY} = $ours;
        }

        if ([] === get_object_vars($extra)) {
            unset($json->extra);
        } else {
            $json->extra = $extra;
        }

        return json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The packages of a lock that don't move in an update (G1): the third-party packages the site
     * added, and every package only they need (one that the site or Quire needs too moves with them).
     *
     * @param array<mixed> $composerJson
     * @param string|null  $except       This third-party package isn't held, nor what only it needs: it's the one moving
     *
     * @return array<string, string> Package => the version it's held at, by name
     */
    public static function held(array $composerJson, Lock $lock, ?string $except = null): array
    {
        $thirdParty = array_keys(array_filter(self::of($composerJson)));

        if ([] === $thirdParty) {
            return [];
        }

        $require = \is_array($composerJson['require'] ?? null) ? array_map(strval(...), array_keys($composerJson['require'])) : [];
        $requireDev = \is_array($composerJson['require-dev'] ?? null) ? array_map(strval(...), array_keys($composerJson['require-dev'])) : [];
        // What the site or Quire needs otherwise moves as it always does; what only a held one needs is
        // held with it, even when the moving one needs it too.
        $free = $lock->needs(array_values(array_diff([...$require, ...$requireDev], $thirdParty)));
        $held = [];

        foreach (array_keys($lock->needs(array_values(array_diff($thirdParty, [$except])))) as $package) {
            if (!isset($free[$package])) {
                $held[$package] = $lock->versions[$package];
            }
        }

        ksort($held);

        return $held;
    }

    /**
     * @return array<mixed>
     */
    public static function read(string $composerJson): array
    {
        try {
            $json = json_decode((string) @file_get_contents($composerJson), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The site\'s composer.json isn\'t JSON.', $e->getCode(), previous: $e);
        }

        return \is_array($json) ? $json : throw new \RuntimeException('The site\'s composer.json isn\'t an object.');
    }
}
