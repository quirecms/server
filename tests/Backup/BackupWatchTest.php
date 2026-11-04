<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use Quire\Server\Backup\BackupLedger;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\BackupWatch;
use Quire\Server\Backup\Restic;
use Quire\Server\Notify\Checks;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;

/**
 * Backups this server made that are gone, which its own retention didn't forget: someone else's doing.
 */
final class BackupWatchTest extends RegistryTestCase
{
    /** @var list<string> What the bucket lists */
    private array $listed = [];
    private bool $opens = true;

    public function testEveryBackupItKeepsIsThere(): void
    {
        $this->made('a', 'b');
        $this->listed = [$this->id('a'), $this->id('b'), $this->id('someone-elses')];

        self::assertSame('Every backup this server made and keeps is there (2).', $this->watch()->repository());
        self::assertSame([], $this->checks()->problems());
    }

    public function testBackupsGoneAreAProblemButNotThoseItForgot(): void
    {
        $this->made('a', 'b', 'c');
        new BackupLedger($this->connection, $this->schema)->forgotten([$this->id('a')]);
        $this->listed = [$this->id('c')];

        self::assertStringStartsWith('1 of the backups this server made are gone from the bucket', $this->watch()->repository());
        $problem = $this->checks()->problems()[0];
        self::assertSame('check:backups.repository', $problem->key);
        self::assertStringContainsString('backups:recover --before=', $problem->advice);
    }

    public function testBackupsInAnotherRepositoryArentMissingFromThisOne(): void
    {
        // Moved to another bucket: those of the first aren't expected in it
        new BackupLedger($this->connection, $this->schema)->record('s3:https://old.example/backups', $this->id('old'), 'quire-server', BackupLedger::SITE, 'jane', new \DateTimeImmutable('2026-11-01T02:00:00Z'));

        self::assertSame('Every backup this server made and keeps is there (0).', $this->watch()->repository());
    }

    public function testARepositoryGoneIsSaidWithWhatItHeld(): void
    {
        $this->made('a', 'b');
        $this->opens = false;

        self::assertSame('The backup repository is gone from the bucket, with the 2 backups this server made.', $this->watch()->repository());
    }

    private function made(string ...$ids): void
    {
        foreach ($ids as $id) {
            new BackupLedger($this->connection, $this->schema)->record('s3:https://s3.example/backups/server-1', $this->id($id), 'quire-server', BackupLedger::SITE, 'jane', new \DateTimeImmutable('2026-11-04T02:00:00Z'));
        }
    }

    private function id(string $start): string
    {
        return str_pad(bin2hex($start), 64, '0');
    }

    private function checks(): Checks
    {
        return new Checks($this->connection, $this->schema);
    }

    private function watch(): BackupWatch
    {
        $settings = new BackupSettings('s3:https://s3.example/backups/server-1', 'key', 'secret', str_repeat('p', 20));
        $runner = new ScriptedRunner([
            'restic --quiet' => fn (array $command): ProcessResult => match ($command[2]) {
                'cat' => $this->opens ? ScriptedRunner::ok($command) : new ProcessResult($command, 10, '', 'repository does not exist'),
                'snapshots' => ScriptedRunner::ok($command, (string) json_encode(array_map(static fn (string $id): array => ['id' => $id, 'time' => '2026-11-04T02:00:00Z', 'tags' => [], 'paths' => []], $this->listed))),
                default => ScriptedRunner::failed($command, 'not scripted'),
            },
        ]);

        return new BackupWatch($settings, new Restic($runner, $settings, '/tmp/cache'), new BackupLedger($this->connection, $this->schema), $this->checks(), new FakeBucket()->client());
    }
}
