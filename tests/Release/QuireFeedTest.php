<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Release;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Release\FeedNotTrusted;
use Quire\Server\Release\GitHubReleases;
use Quire\Server\Release\QuireFeed;
use Quire\Server\Release\ReleaseVerificationFailed;
use Quire\Server\Tests\ScriptedRunner;

/**
 * Quire's release feed from the framework's latest release, or a newer release candidate's own, with
 * GitHub and cosign scripted: read only when signed by the framework's release workflow for that very
 * tag, and refused when a release that must have one has none.
 */
final class QuireFeedTest extends TestCase
{
    private string $latest = 'https://github.com/quirecms/framework/releases/tag/v0.2.0';

    /** @var array<string, string> The latest release's files */
    private array $served = [];

    /** @var array<string, array<string, string>> Other releases' files, by tag ("v0.3.0-rc.1") */
    private array $releases = [];

    /** @var list<string> The tags whose signature checks out for them; empty: every tag */
    private array $signedFor = [];
    /** The status GitHub answers every download with when it's down, as curl -w '%{http_code}' prints it */
    private ?string $githubDown = null;
    private bool $signed = true;
    private bool $sigstoreDown = false;
    private int $now = 1000;
    private ScriptedRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        $this->served = ['feed.json' => FeedTest::json(), 'feed.json.sigstore.json' => '{}'];
        $this->runner = new ScriptedRunner([
            'curl -fsSL' => function (array $command): ProcessResult {
                if (\in_array('%{url_effective}', $command, true)) {
                    return ScriptedRunner::ok($command, $this->latest);
                }

                $url = (string) end($command);
                self::assertStringStartsWith('https://github.com/quirecms/framework/releases/download/', $url);
                $file = basename($url);
                $tag = basename(\dirname($url));
                $served = $tag === basename($this->latest) ? $this->served : $this->releases[$tag] ?? [];

                if (null !== $this->githubDown) {
                    return new ProcessResult($command, '000' === $this->githubDown ? 6 : 22, $this->githubDown, 'curl: (22) The requested URL returned error: '.$this->githubDown);
                }

                if (!isset($served[$file])) {
                    // As curl -f -w '%{http_code}' answers it
                    return new ProcessResult($command, 22, '404', 'curl: (22) The requested URL returned error: 404');
                }

                $at = array_search('-o', $command, true);
                file_put_contents($command[\is_int($at) ? $at + 1 : 0], $served[$file]);

                return ScriptedRunner::ok($command);
            },
            'cosign verify-blob' => fn (array $command): ProcessResult => match (true) {
                $this->sigstoreDown => ScriptedRunner::failed($command, "Error: fetching trusted root: Get \"https://tuf-repo-cdn.sigstore.dev/\": dial tcp: lookup tuf-repo-cdn.sigstore.dev: no such host\n"),
                $this->signed && ([] === $this->signedFor || array_any($this->signedFor, static fn (string $tag): bool => \in_array('https://github.com/quirecms/framework/.github/workflows/release.yaml@refs/tags/'.$tag, $command, true))) => ScriptedRunner::ok($command),
                default => ScriptedRunner::failed($command, 'Error: none of the expected identities matched'),
            },
        ]);
    }

    public function testTheFeedOfTheLatestRelease(): void
    {
        $feed = $this->feed()->read();

        self::assertSame('0.2.0', $feed?->latest->value);
        $cosign = array_merge(...array_map(static fn (array $run): array => $run['command'], array_filter($this->runner->runs, static fn (array $run): bool => 'cosign' === $run['command'][0])));
        self::assertContains('https://github.com/quirecms/framework/.github/workflows/release.yaml@refs/tags/v0.2.0', $cosign);
        self::assertContains('https://token.actions.githubusercontent.com', $cosign);
        self::assertContains('https://github.com/quirecms/framework/releases/download/v0.2.0/feed.json.sigstore.json', array_map(static fn (array $run): string => (string) end($run['command']), $this->runner->runs), 'Its signature\'s bundle is downloaded with it.');
        self::assertContains('--bundle', $cosign);
    }

    public function testAFeedNotSignedByTheFrameworksWorkflowIsRefused(): void
    {
        $this->signed = false;
        $this->expectException(FeedNotTrusted::class);
        $this->expectExceptionMessageIsOrContains('didn\'t check out (it must be signed by https://github.com/quirecms/framework/.github/workflows/release.yaml@refs/tags/v0.2.0): it may have been tampered with');
        $this->feed()->read();
    }

    public function testSigstoreNotAnsweringIsNoAlarm(): void
    {
        $this->sigstoreDown = true;

        try {
            $this->feed()->read();
            self::fail('It was read.');
        } catch (FeedNotTrusted) {
            self::fail('Reported as a feed that doesn\'t check out.');
        } catch (ReleaseVerificationFailed $e) {
            self::assertStringContainsString('couldn\'t be checked: Sigstore didn\'t answer', $e->getMessage());
        }
    }

    public function testALatestReleaseWithoutAFeedIsRefused(): void
    {
        // Every release has a feed since 0.1.2: GitHub answering that it has none isn't "no feed"
        $this->served = [];
        $this->expectException(FeedNotTrusted::class);
        $this->expectExceptionMessageIsOrContains('Quire 0.2.0 has no release feed');
        $this->feed()->read();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outages(): iterable
    {
        // curl exits 22 for every status from 400 on: only "not there" is a verdict
        yield 'forbidden (rate limit)' => ['403'];
        yield 'too many requests' => ['429'];
        yield 'unavailable' => ['503'];
        yield 'no answer' => ['000'];
    }

    #[DataProvider('outages')]
    public function testGitHubNotAnsweringIsAnOutage(string $status): void
    {
        $this->githubDown = $status;

        try {
            $this->feed()->read();
            self::fail('It was read.');
        } catch (FeedNotTrusted) {
            self::fail('Reported as a feed that doesn\'t check out.');
        } catch (ReleaseVerificationFailed $e) {
            self::assertStringContainsString('Quire 0.2.0\'s release feed couldn\'t be got', $e->getMessage());
        }
    }

    public function testAReleaseTheLatestFeedListsIsReadFromIt(): void
    {
        self::assertSame('0.2.0', $this->feed()->read('0.1.1')?->latest->value);
        self::assertSame([], array_filter($this->downloads(), static fn (string $url): bool => !str_contains($url, '/v0.2.0/')), 'Only the latest release\'s feed is got.');
    }

    public function testAnOlderReleaseTheLatestFeedDoesNotListIsRefused(): void
    {
        // The latest's lists every release before it: 0.1.5 isn't one Quire published, and its tag has no feed
        $this->expectException(FeedNotTrusted::class);
        $this->expectExceptionMessageIsOrContains('Quire 0.1.5 has no release feed');
        $this->feed()->read('0.1.5');
    }

    public function testAReleaseCandidateNewerThanTheLatestIsReadFromItsOwnRelease(): void
    {
        $this->releases['v0.3.0-rc.1'] = ['feed.json' => $this->candidate('0.3.0-rc.1'), 'feed.json.sigstore.json' => '{}'];
        $this->signedFor = ['v0.2.0', 'v0.3.0-rc.1'];

        $feed = $this->feed()->read('v0.3.0-rc.1');

        self::assertSame('0.3.0-rc.1', $feed?->latest->value);
        self::assertNotNull($feed->release('0.2.0'), 'It lists the releases before it.');
        self::assertContains('https://github.com/quirecms/framework/releases/download/v0.3.0-rc.1/feed.json', $this->downloads());
    }

    public function testAReleaseCandidatesFeedMustBeSignedForItsOwnTag(): void
    {
        // Signed, but for the latest release's tag: not the candidate's
        $this->releases['v0.3.0-rc.1'] = ['feed.json' => $this->candidate('0.3.0-rc.1'), 'feed.json.sigstore.json' => '{}'];
        $this->signedFor = ['v0.2.0'];
        $this->expectException(FeedNotTrusted::class);
        $this->expectExceptionMessageIsOrContains('it must be signed by https://github.com/quirecms/framework/.github/workflows/release.yaml@refs/tags/v0.3.0-rc.1');
        $this->feed()->read('0.3.0-rc.1');
    }

    public function testAReleaseCandidatesFeedMustEndWithIt(): void
    {
        // rc.1's genuine feed, served for rc.2
        $this->releases['v0.3.0-rc.2'] = ['feed.json' => $this->candidate('0.3.0-rc.1'), 'feed.json.sigstore.json' => '{}'];
        $this->expectExceptionMessageIsOrContains('Quire 0.3.0-rc.2\'s release feed ends with another release (0.3.0-rc.1)');
        $this->feed()->read('0.3.0-rc.2');
    }

    public function testAReleaseCandidateWithoutAFeedIsRefused(): void
    {
        // As Quire 0.5.0-rc.1 was built before: unchecked, with "isn't in Quire's release feed"
        $this->expectException(FeedNotTrusted::class);
        $this->expectExceptionMessageIsOrContains('Quire 0.3.0-rc.1 has no release feed');
        $this->feed()->read('0.3.0-rc.1');
    }

    public function testAReleaseCandidatesFeedIsReadOnceAnHour(): void
    {
        $this->releases['v0.3.0-rc.1'] = ['feed.json' => $this->candidate('0.3.0-rc.1'), 'feed.json.sigstore.json' => '{}'];
        $feed = $this->feed();
        $feed->read('0.3.0-rc.1');
        $runs = \count($this->runner->runs);

        $this->now += 3599;
        self::assertSame('0.3.0-rc.1', $feed->read('0.3.0-rc.1')?->latest->value);
        self::assertSame('0.2.0', $feed->read()?->latest->value);
        self::assertCount($runs, $this->runner->runs);
    }

    public function testAFeedThatEndsWithAnotherReleaseIsRefused(): void
    {
        // An older release's (genuine) feed, served for the latest one
        $this->latest = 'https://github.com/quirecms/framework/releases/tag/v0.2.0';
        $this->served['feed.json'] = (string) json_encode(['schema' => 1, 'releases' => [['version' => '0.1.1', 'date' => '2026-10-27', 'security' => [], 'requires' => ['php' => '>=8.5', 'postgresql' => 18, 'server_contract' => 1]]]]);
        $this->expectExceptionMessageIsOrContains('ends with another release (0.1.1)');
        $this->feed()->read();
    }

    public function testReleasesBeforeTheFeedHaveNone(): void
    {
        $this->latest = 'https://github.com/quirecms/framework/releases/tag/v0.1.1';
        $this->served = [];
        self::assertNull($this->feed()->read());
        self::assertNotContains('cosign', array_map(static fn (array $run): string => $run['command'][0], $this->runner->runs));
    }

    public function testNoReleaseNoFeed(): void
    {
        $this->latest = 'https://github.com/quirecms/framework/releases';
        self::assertNull($this->feed()->read());
    }

    public function testItIsReadOnceAnHour(): void
    {
        $feed = $this->feed();
        $feed->read();
        $runs = \count($this->runner->runs);

        $this->now += 3599;
        $feed->read();
        self::assertCount($runs, $this->runner->runs);

        $this->now += 2;
        $feed->read();
        self::assertGreaterThan($runs, \count($this->runner->runs));
    }

    /**
     * A release candidate's feed: it, then the releases before it.
     */
    private function candidate(string $version): string
    {
        $feed = json_decode(FeedTest::json(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($feed) && \is_array($feed['releases']) && \is_array($feed['releases'][1]));
        array_unshift($feed['releases'], ['version' => $version, 'upgrade' => null] + $feed['releases'][1]);

        return (string) json_encode($feed);
    }

    /**
     * @return list<string> The URLs downloaded
     */
    private function downloads(): array
    {
        return array_values(array_map(static fn (array $run): string => end($run['command']), array_filter($this->runner->runs, static fn (array $run): bool => 'curl' === $run['command'][0] && !\in_array('%{url_effective}', $run['command'], true))));
    }

    private function feed(): QuireFeed
    {
        return new QuireFeed(new GitHubReleases($this->runner), fn (): int => $this->now);
    }
}
