<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\OffsiteBackups;

final class BackupSettingsTest extends TestCase
{
    private const string PASSWORD = 'a-long-random-password-0123456789';

    public function testS3CompatibleStorage(): void
    {
        foreach (['s3:https://fra1.digitaloceanspaces.com/my-backups', 's3:https://s3.eu-central-003.backblazeb2.com/quire-backups/server-1', 's3:https://fsn1.your-objectstorage.com/b-1', 's3:http://minio:9000/backups'] as $repository) {
            $this->settings($repository)->validate();
        }

        self::assertTrue($this->settings('s3:http://minio:9000/backups')->plainHttp());
        self::assertFalse($this->settings('')->configured());
    }

    /**
     * @return iterable<string, array{BackupSettings, string}>
     */
    public static function wrong(): iterable
    {
        yield 'another backend' => [new BackupSettings('sftp:user@host:/backups', 'key', 'secret', self::PASSWORD), 'S3-compatible'];
        yield 'a local folder' => [new BackupSettings('/srv/backups', 'key', 'secret', self::PASSWORD), 'S3-compatible'];
        yield 'no bucket' => [new BackupSettings('s3:https://fra1.digitaloceanspaces.com', 'key', 'secret', self::PASSWORD), 'S3-compatible'];
        yield 'a path that climbs' => [new BackupSettings('s3:https://host/bucket/../other', 'key', 'secret', self::PASSWORD), 'S3-compatible'];
        yield 'no key' => [new BackupSettings('s3:https://host/bucket', '', 'secret', self::PASSWORD), 'ACCESS_KEY'];
        yield 'a key with a space' => [new BackupSettings('s3:https://host/bucket', 'key', 'sec ret', self::PASSWORD), 'SECRET_KEY'];
        yield 'a short password' => [new BackupSettings('s3:https://host/bucket', 'key', 'secret', 'short'), 'too short'];
        yield 'a host that isn\'t a name' => [new BackupSettings('s3:https://host/bucket', 'key', 'secret', self::PASSWORD, host: 'My Server'), 'HOST'];
        yield 'a region that isn\'t one' => [new BackupSettings('s3:https://host/bucket', 'key', 'secret', self::PASSWORD, region: 'eu central'), 'REGION'];
        yield 'a retention without daily' => [new BackupSettings('s3:https://host/bucket', 'key', 'secret', self::PASSWORD, keep: '0 daily, 4 weekly, 6 monthly'), 'KEEP'];
        yield 'a retention in another form' => [new BackupSettings('s3:https://host/bucket', 'key', 'secret', self::PASSWORD, keep: '7d4w6m'), 'KEEP'];
    }

    #[DataProvider('wrong')]
    public function testWhatResticWouldNotTakeIsRefused(BackupSettings $settings, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);
        $settings->validate();
    }

    public function testTheRetention(): void
    {
        self::assertSame(['daily' => 7, 'weekly' => 4, 'monthly' => 6], $this->settings('s3:https://host/bucket')->retention());
        self::assertSame(['daily' => 14, 'weekly' => 0, 'monthly' => 12], new BackupSettings('s3:https://host/bucket', 'k', 's', self::PASSWORD, keep: '14 daily, 0 weekly, 12 monthly')->retention());
    }

    public function testItsSecretsAreKnownToTheLog(): void
    {
        self::assertSame(['secret', self::PASSWORD], $this->settings('s3:https://host/bucket')->secrets());
    }

    public function testTheNightsBackupsAreDueOnceADayFromTheHour(): void
    {
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable($time);

        self::assertFalse(OffsiteBackups::isDue(null, 2, $at('2026-10-28T01:59:00Z')), 'Before the hour');
        self::assertTrue(OffsiteBackups::isDue(null, 2, $at('2026-10-28T02:00:00Z')), 'Never ran');
        self::assertTrue(OffsiteBackups::isDue($at('2026-10-27T02:00:05Z'), 2, $at('2026-10-28T09:00:00Z')), 'Last ran yesterday');
        self::assertFalse(OffsiteBackups::isDue($at('2026-10-28T02:00:05Z'), 2, $at('2026-10-28T09:00:00Z')), 'Ran tonight');
        self::assertFalse(OffsiteBackups::isDue($at('2026-10-28T03:00:00+01:00'), 2, $at('2026-10-28T02:30:00Z')), 'Time zones compared as instants: it ran at 02:00 UTC');
    }

    private function settings(string $repository): BackupSettings
    {
        return new BackupSettings($repository, 'key', 'secret', self::PASSWORD);
    }
}
