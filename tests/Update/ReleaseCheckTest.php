<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use Quire\Server\Process\ProcessResult;
use Quire\Server\Release\GitHubReleases;
use Quire\Server\Release\QuireFeed;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Update\ReleaseCheck;
use Quire\Server\Update\UpdatePlan;

/**
 * What the signed release feed says of an update that changes a site's Quire (the feed scripted, the
 * server's real PostgreSQL asked its version).
 */
final class ReleaseCheckTest extends RegistryTestCase
{
    private bool $tampered = false;

    /** @var list<array<string, mixed>> */
    private array $releases = [];
    private bool $reachable = true;

    public function testAnUpdateThatKeepsQuiresVersionNeedsNoFeed(): void
    {
        $this->reachable = false;
        self::assertSame(['vouched' => true, 'blocked' => [], 'notes' => [], 'security' => []], $this->check('v0.1.1', 'v0.1.1'));
    }

    public function testAReleaseThisServerCanRunIsVouchedFor(): void
    {
        $this->releases = [
            $this->release('0.1.2', security: [['id' => 'GHSA-2c3f-4g5h-6j7m', 'severity' => 'high', 'package' => 'quire/core', 'summary' => 'Drafts could be read.']]),
            $this->release('0.1.1', upgrade: 'Nothing to do.'),
            $this->release('0.1.0'),
        ];
        $check = $this->check('v0.1.0', 'v0.1.2');

        self::assertTrue($check['vouched']);
        self::assertSame([], $check['blocked']);
        self::assertSame(['Quire 0.1.1: Nothing to do.'], $check['notes']);
        self::assertSame(['Quire 0.1.2 fixes GHSA-2c3f-4g5h-6j7m (high, quire/core): Drafts could be read.'], $check['security']);
    }

    public function testANewerServerContractIsRefused(): void
    {
        $this->releases = [$this->release('0.2.0', contract: 2), $this->release('0.1.1')];
        $check = $this->check('v0.1.1', 'v0.2.0');

        self::assertFalse($check['vouched']);
        self::assertStringContainsString('server contract 2', $check['blocked'][0] ?? '');
        self::assertStringContainsString('quire-server update', $check['blocked'][0] ?? '');
    }

    public function testANewerPostgresqlIsRefusedForSitesOnTheServers(): void
    {
        $this->releases = [$this->release('0.2.0', postgresql: 99), $this->release('0.1.1')];

        self::assertStringContainsString('needs PostgreSQL 99', $this->check('v0.1.1', 'v0.2.0')['blocked'][0] ?? '');
        self::assertSame([], $this->check('v0.1.1', 'v0.2.0', Site::OWN_DATABASE)['blocked'], 'A site with its own PostgreSQL gets the one its files say.');
    }

    public function testAReleaseTheFeedDoesNotListIsNotVouchedFor(): void
    {
        $this->releases = [$this->release('0.1.1')];
        $check = $this->check('v0.1.1', 'v0.1.2');

        self::assertFalse($check['vouched']);
        self::assertSame([], $check['blocked']);
        self::assertStringContainsString('isn\'t in Quire\'s release feed', $check['notes'][0] ?? '');
    }

    public function testAFeedThatDoesntCheckOutStopsTheUpdate(): void
    {
        $this->tampered = true;
        $check = $this->check('v0.1.1', 'v0.1.2');

        self::assertFalse($check['vouched']);
        self::assertStringContainsString('may have been tampered with', $check['blocked'][0] ?? '', 'Not a note an update goes ahead after: it stops.');
    }

    public function testAFeedThatCannotBeReadVouchesForNothing(): void
    {
        $this->reachable = false;
        $check = $this->check('v0.1.1', 'v0.1.2');

        self::assertFalse($check['vouched']);
        self::assertStringContainsString('couldn\'t be read', $check['notes'][0] ?? '');
    }

    /**
     * @return array{vouched: bool, blocked: list<string>, notes: list<string>, security: list<string>}
     */
    private function check(string $from, string $to, string $databaseMode = Site::SHARED_DATABASE): array
    {
        $runner = new ScriptedRunner([
            'curl -fsSL' => function (array $command): ProcessResult {
                if (!$this->reachable) {
                    return ScriptedRunner::failed($command, 'curl: (6) Could not resolve host: github.com');
                }

                if (\in_array('%{url_effective}', $command, true)) {
                    $latest = $this->releases[0]['version'] ?? '0.0.1';

                    return ScriptedRunner::ok($command, 'https://github.com/quirecms/framework/releases/tag/v'.(\is_string($latest) ? $latest : ''));
                }

                $at = array_search('-o', $command, true);
                file_put_contents($command[\is_int($at) ? $at + 1 : 0], str_ends_with((string) end($command), '.json') && !str_ends_with((string) end($command), 'sigstore.json') ? json_encode(['schema' => 1, 'releases' => $this->releases]) : '{}');

                return ScriptedRunner::ok($command);
            },
            'cosign verify-blob' => fn (array $command): ProcessResult => $this->tampered ? ScriptedRunner::failed($command, 'Error: none of the expected identities matched') : ScriptedRunner::ok($command),
        ]);
        $plan = new UpdatePlan(new SiteName('jane'), sys_get_temp_dir(), LockTest::lock(['quire/core' => $from]), LockTest::lock(['quire/core' => $to]), '{}', [['package' => 'quire/core', 'from' => $from, 'to' => $to]], [], null, true, [], [], '');
        $site = new Site(new SiteName('jane'), Site::RUNNING, $databaseMode, 'jane', [new Domain('jane.example')], new \DateTimeImmutable());

        return new ReleaseCheck(new QuireFeed(new GitHubReleases($runner)), $this->connection)->check($plan, $site);
    }

    /**
     * @param list<array<string, string>> $security
     *
     * @return array<string, mixed>
     */
    private function release(string $version, array $security = [], ?string $upgrade = null, int $contract = 1, int $postgresql = 18): array
    {
        return ['version' => $version, 'date' => '2026-10-28', 'security' => $security, 'notes' => 'Notes.', 'upgrade' => $upgrade, 'requires' => ['php' => '>=8.5.11', 'postgresql' => $postgresql, 'server_contract' => $contract]];
    }
}
