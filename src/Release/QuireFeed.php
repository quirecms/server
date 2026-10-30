<?php

declare(strict_types=1);

namespace Quire\Server\Release;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Quire's release feed, from the framework's latest GitHub release: feed.json, read only when it was
 * signed by quirecms/framework's release workflow for that release's tag. Read once an hour at most.
 */
final class QuireFeed
{
    public const string REPOSITORY = 'quirecms/framework';

    /** The first release with a feed: from it on, a release without one isn't as it should be */
    private const string FIRST = '0.1.2';
    private const int FRESH_FOR = 3600;

    private ?Feed $feed = null;
    private int $readAt = 0;

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
     * The feed, or null when Quire has no release with one yet.
     *
     * @throws ReleaseVerificationFailed When GitHub or Sigstore can't be reached (an outage)
     * @throws FeedNotTrusted            When the feed was got and doesn't check out
     */
    public function read(): ?Feed
    {
        if ($this->feed instanceof Feed && ($this->now)() - $this->readAt < self::FRESH_FOR) {
            return $this->feed;
        }

        $latest = $this->github->latest(self::REPOSITORY);

        if (!$latest instanceof Version) {
            return null;
        }

        $work = sys_get_temp_dir().'/quire-feed-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($work, 0o755);

        try {
            try {
                $file = $this->github->download(self::REPOSITORY, $latest, 'feed.json', $work);
                $this->github->download(self::REPOSITORY, $latest, 'feed.json.sigstore.json', $work);
            } catch (ReleaseVerificationFailed $e) {
                // Releases before the feed (Quire 0.1.0 and 0.1.1) have none; one after it has.
                if ($latest->compare(new Version(self::FIRST)) >= 0) {
                    throw new ReleaseVerificationFailed(\sprintf('Quire %s\'s release feed couldn\'t be got (%s): what it vouches for, and the security fixes it lists, aren\'t known.', $latest, $e->getMessage()), 0, $e);
                }

                return null;
            }

            if (!$this->github->signed(self::REPOSITORY, $latest, $file, $work)) {
                throw new FeedNotTrusted(\sprintf('The signature of Quire %s\'s release feed didn\'t check out (it must be signed by %s): it may have been tampered with. Not updating on it.', $latest, GitHubReleases::identity(self::REPOSITORY, $latest)));
            }

            try {
                $feed = Feed::parse((string) file_get_contents($file));
            } catch (ReleaseVerificationFailed $e) {
                throw new FeedNotTrusted($e->getMessage().' (it was signed: it may have been tampered with)', 0, $e);
            }

            if ($feed->latest->value !== $latest->value) {
                throw new FeedNotTrusted(\sprintf('Quire %s\'s release feed ends with another release (%s): it may have been tampered with. Not updating on it.', $latest, $feed->latest));
            }
        } finally {
            $this->filesystem->remove($work);
        }

        $this->feed = $feed;
        $this->readAt = ($this->now)();

        return $feed;
    }
}
