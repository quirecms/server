<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Release;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Release\Feed;
use Quire\Server\Release\ReleaseVerificationFailed;

final class FeedTest extends TestCase
{
    public function testItReadsEveryRelease(): void
    {
        $feed = Feed::parse(self::json());

        self::assertSame('0.2.0', $feed->latest->value);
        $release = $feed->release('0.2.0');
        self::assertNotNull($release);
        self::assertSame(['php' => '>=8.5.11', 'postgresql' => 18, 'server_contract' => 2], $release['requires']);
        self::assertSame([['id' => 'GHSA-2c3f-4g5h-6j7m', 'severity' => 'high', 'package' => 'quire/core', 'summary' => 'Drafts could be read.']], $release['security']);
        self::assertSame('Run the migrations.', $release['upgrade']);
        self::assertNull($feed->release('0.1.0')['requires']['server_contract'] ?? null);
        self::assertNull($feed->release('9.9.9'));
    }

    public function testTheReleasesAnUpdateBringsOldestFirst(): void
    {
        $feed = Feed::parse(self::json());

        self::assertSame(['0.1.1', '0.2.0'], array_map(static fn (array $release): string => $release['version']->value, $feed->between('0.1.0', '0.2.0')));
        self::assertSame(['0.1.1'], array_map(static fn (array $release): string => $release['version']->value, $feed->between('0.1.0', '0.1.1')));
        self::assertSame(['0.1.0', '0.1.1'], array_map(static fn (array $release): string => $release['version']->value, $feed->between(null, '0.1.1')));
        self::assertSame([], $feed->between('0.2.0', '0.2.0'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFeeds(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'another schema' => [(string) json_encode(['schema' => 2, 'releases' => [self::release('0.1.0')]])];
        yield 'no releases' => [(string) json_encode(['schema' => 1, 'releases' => []])];
        yield 'releases not a list' => [(string) json_encode(['schema' => 1, 'releases' => ['a' => self::release('0.1.0')]])];
        yield 'a version that isn\'t one' => [(string) json_encode(['schema' => 1, 'releases' => [self::release('0.1.0;rm')]])];
        yield 'a contract that isn\'t a number' => [(string) json_encode(['schema' => 1, 'releases' => [['requires' => ['php' => '>=8.5', 'postgresql' => 18, 'server_contract' => '1']] + self::release('0.1.0')]])];
        yield 'a release that isn\'t an object' => [(string) json_encode(['schema' => 1, 'releases' => ['0.1.0']])];
        yield 'a release without a date' => [(string) json_encode(['schema' => 1, 'releases' => [array_diff_key(self::release('0.1.0'), ['date' => true])]])];
        yield 'advisories that aren\'t a list' => [(string) json_encode(['schema' => 1, 'releases' => [['security' => 'none'] + self::release('0.1.0')]])];
        yield 'requirements that aren\'t an object' => [(string) json_encode(['schema' => 1, 'releases' => [['requires' => '>=8.5'] + self::release('0.1.0')]])];
        yield 'a PHP requirement that isn\'t a string' => [(string) json_encode(['schema' => 1, 'releases' => [['requires' => ['php' => 85, 'postgresql' => 18, 'server_contract' => 1]] + self::release('0.1.0')]])];
        yield 'a PostgreSQL that isn\'t a number' => [(string) json_encode(['schema' => 1, 'releases' => [['requires' => ['php' => '>=8.5', 'postgresql' => '18', 'server_contract' => 1]] + self::release('0.1.0')]])];
        yield 'an advisory whose ID isn\'t a string' => [(string) json_encode(['schema' => 1, 'releases' => [['security' => [['id' => 1234, 'severity' => 'low', 'package' => 'quire/core', 'summary' => 'x']]] + self::release('0.1.0')]])];
        yield 'an advisory without a severity' => [(string) json_encode(['schema' => 1, 'releases' => [['security' => [['id' => 'CVE-2026-1234', 'package' => 'quire/core', 'summary' => 'x']]] + self::release('0.1.0')]])];
        yield 'an advisory without a package' => [(string) json_encode(['schema' => 1, 'releases' => [['security' => [['id' => 'CVE-2026-1234', 'severity' => 'low', 'summary' => 'x']]] + self::release('0.1.0')]])];
        yield 'an advisory without a summary' => [(string) json_encode(['schema' => 1, 'releases' => [['security' => [['id' => 'CVE-2026-1234', 'severity' => 'low', 'package' => 'quire/core']]] + self::release('0.1.0')]])];
    }

    #[DataProvider('notFeeds')]
    public function testAnythingElseIsRefusedAsAWhole(string $json): void
    {
        $this->expectException(ReleaseVerificationFailed::class);
        Feed::parse($json);
    }

    public static function json(): string
    {
        return (string) json_encode(['schema' => 1, 'name' => 'Quire', 'repository' => 'quirecms/framework', 'releases' => [
            ['security' => [['id' => 'GHSA-2c3f-4g5h-6j7m', 'severity' => 'high', 'package' => 'quire/core', 'summary' => 'Drafts could be read.']], 'upgrade' => 'Run the migrations.', 'requires' => ['php' => '>=8.5.11', 'postgresql' => 18, 'server_contract' => 2]] + self::release('0.2.0'),
            self::release('0.1.1'),
            ['requires' => ['php' => '>=8.5.11', 'postgresql' => 18, 'server_contract' => null]] + self::release('0.1.0'),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function release(string $version): array
    {
        return ['version' => $version, 'date' => '2026-10-28', 'security' => [], 'notes' => 'Notes.', 'upgrade' => null, 'requires' => ['php' => '>=8.5.11', 'postgresql' => 18, 'server_contract' => 1], 'skeleton' => ['files' => [], 'changed' => null]];
    }
}
