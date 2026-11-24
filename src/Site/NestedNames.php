<?php

declare(strict_types=1);

namespace Quire\Server\Site;

/**
 * Site names that nest: one is another's and a dash ("jane" and "jane-shop"). Compose names a site's
 * containers, networks and volumes "<project>-…", so jane's own service "shop-web" and jane-shop's
 * "web" would both be "jane-shop-web-1": no rule on a name alone tells them apart, and before
 * jane-shop's scope is valid, the gate gives such a name to jane. So a new site's name never nests in
 * another's, nor another's in it, and the server's set of names stays free of such pairs.
 */
final class NestedNames
{
    /**
     * The other project that nests with this one, or null.
     *
     * @param list<string> $others Every other site's project
     */
    public static function with(string $project, array $others): ?string
    {
        foreach ($others as $other) {
            if ($other !== $project && (str_starts_with($project, $other.'-') || str_starts_with($other, $project.'-'))) {
                return $other;
            }
        }

        return null;
    }

    /**
     * Why a new site can't have this name, or null when it can.
     *
     * @param list<string> $others
     */
    public static function refusal(string $project, array $others): ?string
    {
        $other = self::with($project, $others);

        if (null === $other) {
            return null;
        }

        [$shorter, $longer] = \strlen($other) < \strlen($project) ? [$other, $project] : [$project, $other];

        return \sprintf('"%s" and the site "%s" would nest: %s\'s names would start with "%s-", which are %s\'s. Pick a name that doesn\'t start with another site\'s name and a dash, and that no other site\'s starts with.', $project, $other, $longer, $shorter, $shorter);
    }

    /**
     * The pairs that nest among these projects, each once, shorter first: sites a release before this
     * rule made, which the server lists.
     *
     * @param list<string> $projects
     *
     * @return list<array{string, string}>
     */
    public static function pairs(array $projects): array
    {
        $projects = array_values(array_unique($projects));
        sort($projects);
        $pairs = [];

        foreach ($projects as $i => $project) {
            foreach (\array_slice($projects, $i + 1) as $other) {
                if (str_starts_with($other, $project.'-')) {
                    $pairs[] = [$project, $other];
                } elseif (str_starts_with($project, $other.'-')) {
                    $pairs[] = [$other, $project];
                }
            }
        }

        return $pairs;
    }
}
