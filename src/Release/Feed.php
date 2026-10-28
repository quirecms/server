<?php

declare(strict_types=1);

namespace Quire\Server\Release;

/**
 * Quire's release feed (schema 1), as the framework's release workflow publishes and signs it: every
 * release, with its security fixes, upgrade notes and requirements. Read only once its signature
 * checked out; whatever doesn't have the expected shape is refused as a whole.
 *
 * @phpstan-type Advisory array{id: string, severity: string, package: string, summary: string}
 * @phpstan-type Requirements array{php: string, postgresql: ?int, server_contract: ?int}
 * @phpstan-type FeedRelease array{version: Version, date: string, security: list<Advisory>, upgrade: ?string, requires: Requirements}
 */
final readonly class Feed
{
    /**
     * @param array<string, FeedRelease> $releases By version
     */
    private function __construct(
        public Version $latest,
        private array $releases,
    ) {
    }

    /**
     * @throws ReleaseVerificationFailed When it isn't a feed this server can read
     */
    public static function parse(string $json): self
    {
        try {
            $feed = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ReleaseVerificationFailed('Quire\'s release feed isn\'t JSON.');
        }

        if (!\is_array($feed) || 1 !== ($feed['schema'] ?? null) || !\is_array($feed['releases'] ?? null) || !array_is_list($feed['releases']) || [] === $feed['releases']) {
            throw new ReleaseVerificationFailed('Quire\'s release feed isn\'t one this server reads (schema 1, with releases).');
        }

        $releases = [];

        foreach ($feed['releases'] as $release) {
            $parsed = self::parseRelease($release);
            $releases[$parsed['version']->value] = $parsed;
        }

        $latest = array_reduce($releases, static fn (?Version $carry, array $release): Version => !$carry instanceof Version || $release['version']->isNewerThan($carry) ? $release['version'] : $carry);

        return new self($latest ?? throw new ReleaseVerificationFailed('Quire\'s release feed has no releases.'), $releases);
    }

    /**
     * @return FeedRelease|null
     */
    public function release(string $version): ?array
    {
        return $this->releases[$version] ?? null;
    }

    /**
     * The releases after one version, up to another (included), oldest first: what updating from one
     * to the other brings.
     *
     * @return list<FeedRelease>
     */
    public function between(?string $from, string $to): array
    {
        $after = null === $from ? null : Version::tryFrom($from);
        $upTo = Version::tryFrom($to);

        if (!$upTo instanceof Version) {
            return [];
        }

        $releases = array_values(array_filter($this->releases, static fn (array $release): bool => $release['version']->compare($upTo) <= 0 && (!$after instanceof Version || $release['version']->isNewerThan($after))));
        usort($releases, static fn (array $a, array $b): int => $a['version']->compare($b['version']));

        return $releases;
    }

    /**
     * @return FeedRelease
     */
    private static function parseRelease(mixed $release): array
    {
        if (!\is_array($release) || !\is_string($release['version'] ?? null) || !\is_string($release['date'] ?? null) || !\is_array($release['security'] ?? null) || !\is_array($release['requires'] ?? null)) {
            throw new ReleaseVerificationFailed('A release in Quire\'s release feed isn\'t one this server reads.');
        }

        $version = Version::tryFrom($release['version']) ?? throw new ReleaseVerificationFailed('A release in Quire\'s release feed has no version.');
        $security = [];

        foreach ($release['security'] as $advisory) {
            if (!\is_array($advisory) || !\is_string($advisory['id'] ?? null) || !\is_string($advisory['severity'] ?? null) || !\is_string($advisory['package'] ?? null) || !\is_string($advisory['summary'] ?? null)) {
                throw new ReleaseVerificationFailed(\sprintf('An advisory of Quire %s in its release feed isn\'t one this server reads.', $version));
            }

            $security[] = ['id' => $advisory['id'], 'severity' => $advisory['severity'], 'package' => $advisory['package'], 'summary' => $advisory['summary']];
        }

        $requires = $release['requires'];

        if (!\is_string($requires['php'] ?? null) || !self::intOrNull($requires['postgresql'] ?? null) || !self::intOrNull($requires['server_contract'] ?? null)) {
            throw new ReleaseVerificationFailed(\sprintf('What Quire %s requires, in its release feed, isn\'t something this server reads.', $version));
        }

        $upgrade = $release['upgrade'] ?? null;

        return [
            'version' => $version,
            'date' => $release['date'],
            'security' => $security,
            'upgrade' => \is_string($upgrade) ? $upgrade : null,
            'requires' => ['php' => $requires['php'], 'postgresql' => $requires['postgresql'] ?? null, 'server_contract' => $requires['server_contract'] ?? null],
        ];
    }

    /**
     * @phpstan-assert-if-true int|null $value
     */
    private static function intOrNull(mixed $value): bool
    {
        return null === $value || \is_int($value);
    }
}
