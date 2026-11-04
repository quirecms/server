<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use PHPUnit\Framework\TestCase;
use Quire\Server\Backup\BackupFailed;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\Restic;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Tests\ScriptedRunner;

/**
 * restic, scripted as it answers: its keys and password only in its environment, its answers read.
 */
final class ResticTest extends TestCase
{
    private const string PASSWORD = 'a-long-random-password-0123456789';

    /** @var array<string, array{int, string, string}> restic's subcommand => its exit code, output and errors */
    private array $answers = [];
    private ScriptedRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        $this->runner = new ScriptedRunner([
            'restic --quiet' => function (array $command): ProcessResult {
                [$exit, $output, $error] = $this->answers[$command[2]] ?? [0, '', ''];

                return new ProcessResult($command, $exit, $output, $error);
            },
        ]);
    }

    public function testItsSecretsAreOnlyInItsEnvironment(): void
    {
        $this->answers['backup'] = [0, "{\"message_type\":\"status\"}\n{\"message_type\":\"summary\",\"snapshot_id\":\"1a2b3c4d5e6f\"}\n", ''];
        $this->restic()->backup(['/srv/quire/backups/jane/off-server.tar'], ['quire-site', 'site:jane']);
        $run = $this->runner->runs[0];

        self::assertSame(['restic', '--quiet', 'backup', '--json', '--host', 'quire-server', '--tag', 'quire-site', '--tag', 'site:jane', '--', '/srv/quire/backups/jane/off-server.tar'], $run['command']);
        self::assertStringNotContainsString(self::PASSWORD, implode(' ', $run['command']));
        self::assertStringNotContainsString('the-secret', implode(' ', $run['command']));
        self::assertSame(['s3:https://host/bucket', self::PASSWORD, 'the-key', 'the-secret'], [$run['env']['RESTIC_REPOSITORY'], $run['env']['RESTIC_PASSWORD'], $run['env']['AWS_ACCESS_KEY_ID'], $run['env']['AWS_SECRET_ACCESS_KEY']]);
        self::assertSame('us-east-1', $run['env']['AWS_DEFAULT_REGION'], 'Not asked of the bucket: not every provider answers.');
        self::assertFalse($run['env']['RESTIC_PASSWORD_COMMAND'], 'Nothing inherited names another password.');
        self::assertFalse($run['env']['RESTIC_PASSWORD_FILE']);
    }

    public function testTheSnapshotItMade(): void
    {
        $this->answers['backup'] = [0, "{\"message_type\":\"summary\",\"snapshot_id\":\"1a2b3c4d5e6f\"}\n", ''];
        self::assertSame('1a2b3c4d5e6f', $this->restic()->backup(['/x'], []));

        $this->answers['backup'] = [0, "{\"message_type\":\"status\"}\n", ''];
        $this->expectExceptionMessageIsOrContains('didn\'t say which snapshot');
        $this->restic()->backup(['/x'], []);
    }

    public function testWhetherTheRepositoryOpens(): void
    {
        self::assertTrue($this->restic()->opens());

        $this->answers['cat'] = [10, '', 'Fatal: repository does not exist'];
        self::assertFalse($this->restic()->opens());

        $this->answers['cat'] = [12, '', 'Fatal: wrong password or no key found'];

        try {
            $this->restic()->opens();
            self::fail('A wrong password was taken for a missing repository.');
        } catch (BackupFailed $e) {
            self::assertStringContainsString('doesn\'t open with this password', $e->getMessage());
        }

        $this->answers['cat'] = [1, '', "Fatal: unable to open config file: Stat: The AWS Access Key Id you provided does not exist in our records.\n"];
        $this->expectExceptionMessageIsOrContains('can\'t be reached: Fatal: unable to open config file');
        $this->restic()->opens();
    }

    public function testThisServersSnapshotsOfATag(): void
    {
        $this->answers['snapshots'] = [0, (string) json_encode([
            ['id' => 'aaaa1111', 'time' => '2026-10-27T02:00:00Z', 'tags' => ['quire-site', 'site:jane'], 'paths' => ['/srv/quire/backups/jane/off-server-1.tar']],
            ['id' => 'bbbb2222', 'time' => '2026-10-28T02:00:00Z', 'tags' => ['quire-site', 'site:jane'], 'paths' => ['/srv/quire/backups/jane/off-server-2.tar']],
            'not a snapshot',
        ]), ''];

        self::assertSame(['aaaa1111', 'bbbb2222'], array_column($this->restic()->snapshots(['quire-site', 'site:jane']), 'id'));
        self::assertSame(['restic', '--quiet', 'snapshots', '--json', '--host', 'quire-server', '--tag', 'quire-site,site:jane'], $this->runner->runs[0]['command']);
    }

    public function testTheRetentionKeepsEachSitesOnItsOwnAndSaysWhatItForgot(): void
    {
        $this->answers['forget'] = [0, (string) json_encode([
            ['tags' => ['quire-site', 'site:jane'], 'keep' => [['id' => str_repeat('a', 64)]], 'remove' => [['id' => str_repeat('b', 64)], ['id' => str_repeat('c', 64)]]],
            ['tags' => ['quire-server'], 'keep' => [['id' => str_repeat('d', 64)]], 'remove' => null],
        ]), ''];

        self::assertSame([str_repeat('b', 64), str_repeat('c', 64)], $this->restic()->forget(prune: true), 'What the registry stops expecting to find.');
        self::assertSame(['restic', '--quiet', 'forget', '--json', '--host', 'quire-server', '--group-by', 'host,tags', '--keep-daily', '7', '--keep-weekly', '4', '--keep-monthly', '6'], $this->runner->runs[0]['command']);
        self::assertSame(['restic', '--quiet', 'prune'], $this->runner->runs[1]['command'], 'On its own: its output isn\'t forget\'s list.');
    }

    public function testARestoresSizeIsItsFiles(): void
    {
        $this->answers['ls'] = [0, implode("\n", [
            '{"time":"2026-11-01T02:00:00Z","struct_type":"snapshot","id":"'.str_repeat('a', 64).'"}',
            '{"name":"jane","type":"dir","path":"/srv/quire/sites/jane","size":0}',
            '{"name":"a.tar","type":"file","path":"/srv/quire/backups/jane/a.tar","size":1500}',
            '{"name":"b","type":"file","path":"/srv/quire/sites/jane/b","size":500}',
        ]), ''];

        self::assertSame(2000, $this->restic()->size(str_repeat('a', 64), ['/srv/quire/backups/jane/a.tar', '/srv/quire/sites/jane']));
        self::assertSame(['restic', '--quiet', 'ls', '--json', '--recursive', str_repeat('a', 64), '--', '/srv/quire/backups/jane/a.tar', '/srv/quire/sites/jane'], $this->runner->runs[0]['command']);
    }

    public function testOnlyASnapshotAndAPathInIt(): void
    {
        foreach ([['latest;rm', '/x'], ['aaaa1111', 'relative'], ['aaaa1111', '/srv/../etc/shadow'], ['--target=/', '/x']] as [$snapshot, $path]) {
            try {
                $this->restic()->restoreFile($snapshot, $path, '/tmp/into');
                self::fail(\sprintf('"%s" "%s" was restored.', $snapshot, $path));
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $this->runner->runs);
    }

    public function testOnlyTags(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->restic()->backup(['/x'], ['--exclude=/']);
    }

    private function restic(): Restic
    {
        return new Restic($this->runner, new BackupSettings('s3:https://host/bucket', 'the-key', 'the-secret', self::PASSWORD), '/srv/quire/backups/.restic-cache');
    }

    public function testWhatABuildMakesAgainIsLeftOut(): void
    {
        $this->answers['backup'] = [0, "{\"message_type\":\"summary\",\"snapshot_id\":\"1a2b3c4d\"}\n", ''];
        $this->restic()->backup(['/srv/quire/backups/jane/x.tar', '/srv/quire/sites/jane'], ['quire-site'], ['/srv/quire/sites/jane/vendor', '/srv/quire/sites/jane/var']);

        self::assertSame(['restic', '--quiet', 'backup', '--json', '--host', 'quire-server', '--tag', 'quire-site', '--exclude=/srv/quire/sites/jane/vendor', '--exclude=/srv/quire/sites/jane/var', '--', '/srv/quire/backups/jane/x.tar', '/srv/quire/sites/jane'], $this->runner->runs[0]['command']);

        $this->expectException(\InvalidArgumentException::class);
        $this->restic()->backup(['/x'], [], ['relative/vendor']);
    }

    public function testAnotherServersSnapshots(): void
    {
        $this->answers['snapshots'] = [0, '[]', ''];
        $this->restic()->snapshots(['quire-site'], 'old-server');
        self::assertSame('old-server', $this->runner->runs[0]['command'][5]);

        $this->expectException(\InvalidArgumentException::class);
        $this->restic()->snapshots([], 'Old Server;rm');
    }

    public function testRestoringSeveralPaths(): void
    {
        $this->restic()->restorePaths('1a2b3c4d', ['/srv/quire/backups/jane/x.tar', '/srv/quire/sites/jane'], '/srv/quire/backups/.restoring');
        self::assertSame(['restic', '--quiet', 'restore', '1a2b3c4d', '--target', '/srv/quire/backups/.restoring', '--include', '/srv/quire/backups/jane/x.tar', '--include', '/srv/quire/sites/jane'], $this->runner->runs[0]['command']);
    }
}
