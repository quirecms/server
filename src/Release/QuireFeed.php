<?php

declare(strict_types=1);

namespace Quire\Server\Release;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Quire's release feed: feed.json of a framework release, read only when it was signed by
 * quirecms/framework's release workflow for that release's tag. The latest release's lists every
 * release before it; a release candidate newer than the latest (0.5.0-rc.1 while 0.4.0 is the latest)
 * is only in its own, which is read for it. Each is read once an hour at most.
 */
final class QuireFeed
{
    public const string REPOSITORY = 'quirecms/framework';

    /** The first release with a feed: from it on, a release without one isn't as it should be */
    private const string FIRST = '0.1.2';
    private const int FRESH_FOR = 3600;

    /** @var array<string, array{?Feed, int}> By the release whose feed it is ("" for the latest) */
    private array $read = [];

    /**
     * @param \Closure(): int $now
     */
    public function __construct(
        private readonly GitHubReleases $github,
        private readonly \Closure $now = time(...),
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * The feed that lists a release (the latest's, or that release's own when the latest's doesn't),
     * or the latest's without one; null when Quire has no release with one yet.
     *
     * @throws ReleaseVerificationFailed When GitHub or Sigstore can't be reached (an outage)
     * @throws FeedNotTrusted            When the feed was got and doesn't check out, or a release
     *                                   that must have one has none
     */
    public function read(Version|string|null $for = null): ?Feed
    {
        $for = \is_string($for) ? Version::tryFrom(ltrim($for, 'v')) : $for;
        $feed = $this->cached('', fn (): ?Feed => $this->latest());

        if (!$for instanceof Version || ($feed instanceof Feed && null !== $feed->release($for->value))) {
            return $feed;
        }

        // Only a release the latest's feed doesn't list: a newer one (a release candidate), or one
        // that isn't Quire's at all (the latest's lists every release before it), which its own
        // release then says: GitHub has no feed for it
        return $this->cached($for->value, fn (): Feed => $this->of($for) ?? throw new FeedNotTrusted(\sprintf('Quire %s has no release feed: it isn\'t a release Quire published. Not updating to it.', $for)));
    }

    /**
     * @param \Closure(): ?Feed $read
     */
    private function cached(string $key, \Closure $read): ?Feed
    {
        if (isset($this->read[$key]) && ($this->now)() - $this->read[$key][1] < self::FRESH_FOR) {
            return $this->read[$key][0];
        }

        $feed = $read();
        $this->read[$key] = [$feed, ($this->now)()];

        return $feed;
    }

    private function latest(): ?Feed
    {
        $latest = $this->github->latest(self::REPOSITORY);

        return $latest instanceof Version ? $this->of($latest) : null;
    }

    /**
     * A release's own feed, or null for a release from before the feed.
     */
    private function of(Version $release): ?Feed
    {
        $work = sys_get_temp_dir().'/quire-feed-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($work, 0o755);

        try {
            try {
                $file = $this->github->download(self::REPOSITORY, $release, 'feed.json', $work);
                $this->github->download(self::REPOSITORY, $release, 'feed.json.sigstore.json', $work);
            } catch (ReleaseVerificationFailed $e) {
                // Releases before the feed (Quire 0.1.0 and 0.1.1) have none; one after it has.
                if ($release->compare(new Version(self::FIRST)) < 0) {
                    return null;
                }

                // GitHub answered that it isn't there: not an outage, a release that isn't as Quire
                // publishes them (or no release at all), so nothing is vouched for and nothing goes ahead
                if (GitHubReleases::MISSING === $e->getCode()) {
                    throw new FeedNotTrusted(\sprintf('Quire %s has no release feed (%s): it isn\'t a release Quire published. Not updating to it.', $release, $e->getMessage()), 0, $e);
                }

                throw new ReleaseVerificationFailed(\sprintf('Quire %s\'s release feed couldn\'t be got (%s): what it vouches for, and the security fixes it lists, aren\'t known.', $release, $e->getMessage()), 0, $e);
            }

            if (!$this->github->signed(self::REPOSITORY, $release, $file, $work)) {
                throw new FeedNotTrusted(\sprintf('The signature of Quire %s\'s release feed didn\'t check out (it must be signed by %s): it may have been tampered with. Not updating on it.', $release, GitHubReleases::identity(self::REPOSITORY, $release)));
            }

            try {
                $feed = Feed::parse((string) file_get_contents($file));
            } catch (ReleaseVerificationFailed $e) {
                throw new FeedNotTrusted($e->getMessage().' (it was signed: it may have been tampered with)', 0, $e);
            }

            if ($feed->latest->value !== $release->value) {
                throw new FeedNotTrusted(\sprintf('Quire %s\'s release feed ends with another release (%s): it may have been tampered with. Not updating on it.', $release, $feed->latest));
            }
        } finally {
            $this->filesystem->remove($work);
        }

        return $feed;
    }
}
