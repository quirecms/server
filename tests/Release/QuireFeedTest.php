<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Release;

use PHPUnit\Framework\TestCase;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Release\FeedNotTrusted;
use Quire\Server\Release\GitHubReleases;
use Quire\Server\Release\QuireFeed;
use Quire\Server\Release\ReleaseVerificationFailed;
use Quire\Server\Tests\ScriptedRunner;

/**
 * Quire's release feed from the framework's latest release, with GitHub and cosign scripted: read only
 * when signed by the framework's release workflow for that very tag.
 */
final class QuireFeedTest extends TestCase
{
    private string $latest = 'https://github.com/quirecms/framework/releases/tag/v0.2.0';

    /** @var array<string, string> */
    private array $served = [];
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

                if (!isset($this->served[$file])) {
                    return ScriptedRunner::failed($command, 'curl: (22) The requested URL returned error: 404');
                }

                $at = array_search('-o', $command, true);
                file_put_contents($command[\is_int($at) ? $at + 1 : 0], $this->served[$file]);

                return ScriptedRunner::ok($command);
            },
            'cosign verify-blob' => fn (array $command): ProcessResult => match (true) {
                $this->sigstoreDown => ScriptedRunner::failed($command, "Error: fetching trusted root: Get \"https://tuf-repo-cdn.sigstore.dev/\": dial tcp: lookup tuf-repo-cdn.sigstore.dev: no such host\n"),
                $this->signed => ScriptedRunner::ok($command),
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

    public function testALatestReleaseWithoutAFeedIsNoQuietNull(): void
    {
        // Every release has a feed since 0.1.2: one that can't be got isn't "no feed"
        $this->served = [];
        $this->expectException(ReleaseVerificationFailed::class);
        $this->expectExceptionMessageIsOrContains('Quire 0.2.0\'s release feed couldn\'t be got');
        $this->feed()->read();
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

    private function feed(): QuireFeed
    {
        return new QuireFeed(new GitHubReleases($this->runner), fn (): int => $this->now);
    }
}
