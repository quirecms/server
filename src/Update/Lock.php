<?php

declare(strict_types=1);

namespace Quire\Server\Update;

/**
 * A composer.lock, read: which version of each package a site runs.
 */
final readonly class Lock
{
    /**
     * @param array<string, string> $versions Package => version, by name
     */
    private function __construct(
        public array $versions,
        public string $content,
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

        foreach ([...$lock['packages'], ...(\is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [])] as $package) {
            if (\is_array($package) && \is_string($package['name'] ?? null) && \is_string($package['version'] ?? null)) {
                $versions[$package['name']] = $package['version'];
            }
        }

        ksort($versions);

        return new self($versions, $content);
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
