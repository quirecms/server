<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use PHPUnit\Framework\TestCase;
use Quire\Server\Backup\BackupProtection;
use Quire\Server\Backup\BackupRecovery;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\S3;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The server's own S3 requests (D-37): signed as AWS signs them, the probe of what an attacker with
 * server.env could do to the backups, and their recovery from the versions a protected bucket keeps.
 */
final class BucketTest extends TestCase
{
    public function testRequestsAreSignedAsAwsDocumentsIt(): void
    {
        // AWS's own example (S3 docs, "Signature Calculations for the Authorization Header": GET Object)
        $s3 = new S3(new MockHttpClient(), 'https://examplebucket.s3.amazonaws.com', 'examplebucket', '', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
        $headers = $s3->sign('GET', '/test.txt', [], ['range' => 'bytes=0-9'], '', new \DateTimeImmutable('2013-05-24T00:00:00Z'));

        self::assertSame('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $headers['authorization']);
    }

    public function testTheRepositorysBucketAndFolder(): void
    {
        $s3 = S3::of(new BackupSettings('s3:https://fra1.digitaloceanspaces.com/my-backups/quire/server-1', 'k', 's', str_repeat('p', 20)), new MockHttpClient());

        self::assertSame(['my-backups', 'quire/server-1', 'quire/server-1/quire-probe'], [$s3->bucket, $s3->prefix, $s3->key('quire-probe')]);
    }

    public function testABucketWithACompliantLockProtectsTheBackups(): void
    {
        $result = $this->probe(new FakeBucket(FakeBucket::COMPLIANCE, 30));

        self::assertTrue($result['protected']);
        self::assertSame('Backups off the server are protected: what\'s deleted stays recoverable for 30 days (compliance retention).', $result['summary']);
    }

    public function testWhatAnAttackerCouldDoIsSaid(): void
    {
        foreach ([
            [new FakeBucket(FakeBucket::UNVERSIONED), 'the bucket doesn\'t keep versions'],
            [new FakeBucket(FakeBucket::UNLOCKED), 'can delete a backup\'s version for good'],
            [new FakeBucket(FakeBucket::GOVERNANCE, mayBypass: true), 'can delete a backup\'s version for good'],
        ] as [$bucket, $expected]) {
            $result = $this->probe($bucket);
            self::assertFalse($result['protected']);
            self::assertStringContainsString($expected, $result['summary']);
            self::assertStringContainsString('Object Lock', $result['advice']);
        }

        self::assertTrue($this->probe(new FakeBucket(FakeBucket::GOVERNANCE))['protected'], 'Governance that these keys can\'t bypass holds against them.');
    }

    public function testATooShortRetentionIsntEnough(): void
    {
        $result = $this->probe(new FakeBucket(FakeBucket::COMPLIANCE, 2));

        self::assertFalse($result['protected']);
        self::assertStringContainsString('protected for 2 day(s) only', $result['summary']);
    }

    public function testTheProbeOnlyTouchesItsOwnObject(): void
    {
        $bucket = new FakeBucket();
        $this->probe($bucket);

        foreach ($bucket->requests as [, $url]) {
            self::assertStringStartsWith('https://s3.example/backups/server-1/quire-probe', $url);
        }

        self::assertSame(['server-1/quire-probe'], array_keys($bucket->objects));
    }

    public function testDeletedBackupsComeBackAsTheyWere(): void
    {
        $bucket = new FakeBucket(now: '2026-11-03T02:00:00Z');
        $client = $bucket->client();
        $s3 = $this->s3($client);
        $s3->put('server-1/config', 'config');
        $s3->put('server-1/snapshots/aaa', 'a');
        $s3->put('server-1/data/00/bbb', 'b');
        $client->request('DELETE', 'https://s3.example/backups/server-1/data/00/bbb');
        // The night after, a new backup; then someone with server.env deletes everything
        $bucket->now = '2026-11-04T02:00:00Z';
        $s3->put('server-1/snapshots/ccc', 'c');
        $bucket->now = '2026-11-04T09:00:00Z';

        foreach (['server-1/config', 'server-1/snapshots/aaa', 'server-1/snapshots/ccc'] as $key) {
            $client->request('DELETE', 'https://s3.example/backups/'.$key);
        }

        // As it was at 08:00: the night's new backup (ccc) included
        self::assertSame(['restored' => ['server-1/config', 'server-1/snapshots/aaa', 'server-1/snapshots/ccc'], 'newer' => 0, 'failed' => []], new BackupRecovery($s3)->recover(new \DateTimeImmutable('2026-11-04T08:00:00Z'), dryRun: true));
        self::assertCount(2, $bucket->objects['server-1/config'], 'A dry run changes nothing.');

        $outcome = new BackupRecovery($s3)->recover(new \DateTimeImmutable('2026-11-04T08:00:00Z'));
        self::assertSame(['server-1/config', 'server-1/snapshots/aaa', 'server-1/snapshots/ccc'], $outcome['restored']);
        // A backup made after that time is kept as it is
        $bucket->now = '2026-11-04T10:00:00Z';
        $s3->put('server-1/snapshots/ddd', 'd');
        self::assertSame(['restored' => [], 'newer' => 1, 'failed' => []], new BackupRecovery($s3)->recover(new \DateTimeImmutable('2026-11-04T08:00:00Z'), dryRun: true));
        self::assertSame([false, true], [$this->lastIsMarker($bucket, 'server-1/config'), $this->lastIsMarker($bucket, 'server-1/data/00/bbb')], 'config current again; bbb, deleted before that time (restic forgot it), left so.');
    }

    private function lastIsMarker(FakeBucket $bucket, string $key): bool
    {
        $last = array_last($bucket->objects[$key] ?? []);
        self::assertIsArray($last);

        return $last['marker'];
    }

    /**
     * @return array{protected: bool, summary: string, advice: string}
     */
    private function probe(FakeBucket $bucket): array
    {
        return new BackupProtection($this->s3($bucket->client()), static fn (): \DateTimeImmutable => new \DateTimeImmutable($bucket->now))->probe();
    }

    private function s3(MockHttpClient $client): S3
    {
        return new S3($client, 'https://s3.example', 'backups', 'server-1', 'key', 'secret', 'us-east-1');
    }
}
