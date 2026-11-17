<?php

declare(strict_types=1);

namespace Quire\Server\Update;

/**
 * A composer.lock, read: which version of each package a site runs.
 */
final readonly class Lock
{
    /**
     * @param array<string, string>                                                                                                                                                                                                                $versions Package => version, by name
     * @param array<string, array{type: string, require: list<string>, replace: list<string>, provide: list<string>, dist: array{url: ?string, reference: ?string}, source: array{url: ?string, reference: ?string}, id: string, parent: ?string}> $details  Package => what it is and where it comes from
     */
    private function __construct(
        public array $versions,
        public string $content,
        public array $details = [],
    ) {
    }

    public static function parse(string $content): self
    {
        try {
            $lock = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The composer.lock isn\'t JSON.', $e->getCode(), previous: $e);
        }

        if (!\is_array($lock) || !\is_array($lock['packages'] ?? null)) {
            throw new \RuntimeException('The composer.lock has no packages.');
        }

        $versions = [];
        $details = [];

        foreach ([...$lock['packages'], ...(\is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [])] as $package) {
            if (\is_array($package) && \is_string($package['name'] ?? null) && \is_string($package['version'] ?? null)) {
                $versions[$package['name']] = $package['version'];
                $details[$package['name']] = [
                    // Composer's default
                    'type' => \is_string($package['type'] ?? null) ? $package['type'] : 'library',
                    'require' => self::names($package['require'] ?? null),
                    'replace' => self::names($package['replace'] ?? null),
                    'provide' => self::names($package['provide'] ?? null),
                    'dist' => self::origin($package['dist'] ?? null),
                    'source' => self::origin($package['source'] ?? null),
                    ...self::extension($package['name'], $package['extra'] ?? null),
                ];
            }
        }

        ksort($versions);
        ksort($details);

        return new self($versions, $content, $details);
    }

    public static function read(string $path): self
    {
        $content = @file_get_contents($path);

        if (false === $content) {
            throw new \RuntimeException(\sprintf('Couldn\'t read "%s".', $path));
        }

        return self::parse($content);
    }

    /**
     * The version of Quire, i.e. of quire/core (null when it isn't there).
     */
    public function quire(): ?string
    {
        return $this->versions['quire/core'] ?? null;
    }

    /**
     * @return list<string> The packages a "require", "replace" or "provide" names, by name
     */
    private static function names(mixed $links): array
    {
        $names = \is_array($links) ? array_map(strval(...), array_keys($links)) : [];
        sort($names);

        return $names;
    }

    /**
     * Its id as a Quire extension or theme (extra.quire.id, or "acme/reviews" → "acme-reviews", as
     * Quire's ManifestParser has it), and a theme's parent's.
     *
     * @return array{id: string, parent: ?string}
     */
    private static function extension(string $name, mixed $extra): array
    {
        $quire = \is_array($extra) && \is_array($extra['quire'] ?? null) ? $extra['quire'] : [];
        $theme = \is_array($quire['theme'] ?? null) ? $quire['theme'] : [];

        return [
            'id' => \is_string($quire['id'] ?? null) ? $quire['id'] : trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'),
            'parent' => \is_string($theme['parent'] ?? null) ? $theme['parent'] : null,
        ];
    }

    /**
     * @return array{url: ?string, reference: ?string}
     */
    private static function origin(mixed $origin): array
    {
        return [
            'url' => \is_array($origin) && \is_string($origin['url'] ?? null) ? $origin['url'] : null,
            'reference' => \is_array($origin) && \is_string($origin['reference'] ?? null) && '' !== $origin['reference'] ? $origin['reference'] : null,
        ];
    }

    /**
     * The packages of this lock these need, themselves among them, and what those need in turn.
     *
     * @param list<string> $packages
     *
     * @return array<string, true> By name
     */
    public function needs(array $packages): array
    {
        $found = [];
        $next = $packages;

        while ([] !== $next) {
            $package = array_pop($next);

            // A platform package ("php", "ext-intl") or a virtual one isn't in the lock.
            if (isset($found[$package]) || !isset($this->details[$package])) {
                continue;
            }

            $found[$package] = true;
            array_push($next, ...$this->details[$package]['require']);
        }

        ksort($found);

        return $found;
    }

    /**
     * What changes from this lock to another one.
     *
     * @return list<array{package: string, from: ?string, to: ?string}> By name: updated, added (from null), removed (to null)
     */
    public function changesTo(self $other): array
    {
        $changes = [];

        foreach (array_unique([...array_keys($this->versions), ...array_keys($other->versions)]) as $package) {
            $from = $this->versions[$package] ?? null;
            $to = $other->versions[$package] ?? null;

            if ($from !== $to) {
                $changes[$package] = ['package' => $package, 'from' => $from, 'to' => $to];
            }
        }

        ksort($changes);

        return array_values($changes);
    }
}
