<?php

declare(strict_types=1);

namespace Quire\Server\Release;

/**
 * A release's version, e.g. "0.2.1" or "0.3.0-rc.1" (semantic versioning, without build metadata):
 * it names a tag, a folder and a download, so only these characters are allowed.
 */
final readonly class Version implements \Stringable
{
    private const string PATTERN = '/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})(?:-([0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*))?\z/';

    public string $value;

    /** @var array{int, int, int} */
    private array $numbers;
    private ?string $preRelease;

    public function __construct(string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value, $match) || \strlen($value) > 64) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a version, e.g. "0.2.1".', substr((string) preg_replace('/[^\x20-\x7e]/', '?', $value), 0, 64)));
        }

        $this->value = $value;
        $this->numbers = [(int) $match[1], (int) $match[2], (int) $match[3]];
        $this->preRelease = $match[4] ?? null;
    }

    /**
     * A version, or null for one built from a checkout ("dev") or none.
     */
    public static function tryFrom(string $value): ?self
    {
        try {
            return new self($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Negative, zero or positive, as this version is older than, the same as or newer than the other.
     */
    public function compare(self $other): int
    {
        $numbers = $this->numbers <=> $other->numbers;

        if (0 !== $numbers) {
            return $numbers;
        }

        // A pre-release comes before its release; pre-releases compare by their dot-separated parts.
        return match (true) {
            $this->preRelease === $other->preRelease => 0,
            null === $this->preRelease => 1,
            null === $other->preRelease => -1,
            default => $this->comparePreReleases($this->preRelease, $other->preRelease),
        };
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function isPreRelease(): bool
    {
        return null !== $this->preRelease;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }

    private function comparePreReleases(string $a, string $b): int
    {
        $left = explode('.', $a);
        $right = explode('.', $b);

        foreach ($left as $i => $part) {
            if (!isset($right[$i])) {
                return 1;
            }

            $other = $right[$i];
            $numeric = ctype_digit($part);
            $otherNumeric = ctype_digit($other);
            $order = match (true) {
                $numeric && $otherNumeric => (int) $part <=> (int) $other,
                $numeric => -1,
                $otherNumeric => 1,
                default => strcmp($part, $other) <=> 0,
            };

            if (0 !== $order) {
                return $order;
            }
        }

        return \count($left) <=> \count($right);
    }
}
