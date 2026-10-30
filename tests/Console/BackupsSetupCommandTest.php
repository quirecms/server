<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Console;

use PHPUnit\Framework\TestCase;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Console\BackupsSetupCommand;
use Quire\Server\Process\ProcessResult;
use Quire\Server\ServerUpdate\ServerFolder;
use Quire\Server\ServerUpdate\ServerStack;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Setting up backups on a server whose backups are set up already, with restic and Docker scripted:
 * the password in server.env is the only way into the backups made so far.
 */
final class BackupsSetupCommandTest extends TestCase
{
    private const string BEFORE = "QUIRE_SERVER_BACKUP_REPOSITORY='s3:https://old.example.com/backups'\nQUIRE_SERVER_BACKUP_PASSWORD='the old password'\n";

    private string $root;
    private ScriptedRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-backups-setup-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/server/server.env', self::BEFORE);
        $this->runner = new ScriptedRunner([
            // No repository at the new place yet (restic's exit code 10), then one is made.
            'restic --quiet' => static fn (array $command): ProcessResult => 'cat' === $command[2] ? new ProcessResult($command, 10, '', 'repository does not exist') : ScriptedRunner::ok($command),
            'docker compose up' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
        ]);
        putenv('QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY=newSecretKey0123456789');
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY');
        new Filesystem()->remove($this->root);
    }

    public function testItIsntReplacedUnasked(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['no']);
        $status = $tester->execute(['--repository' => 's3:https://new.example.com/backups', '--access-key' => 'new'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
        self::assertSame(self::BEFORE, file_get_contents($this->root.'/server/server.env'));
        self::assertSame([], $this->runner->runs, 'Not even the bucket was asked.');
    }

    public function testWithNoOneToAskItIsntReplaced(): void
    {
        $status = $this->tester()->execute(['--repository' => 's3:https://new.example.com/backups', '--access-key' => 'new', '--region' => 'fra1'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(self::BEFORE, file_get_contents($this->root.'/server/server.env'));
    }

    public function testReplacingItKeepsTheSettingsBefore(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['--repository' => 's3:https://new.example.com/backups', '--access-key' => 'new', '--region' => 'fra1', '--yes' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('s3:https://new.example.com/backups', (string) file_get_contents($this->root.'/server/server.env'));
        $kept = glob($this->root.'/server/server.env.before-*');
        self::assertCount(1, false === $kept ? [] : $kept);
        self::assertSame(self::BEFORE, file_get_contents($kept[0] ?? ''), 'The old password stays on the server.');
        self::assertSame(0o600, fileperms($kept[0] ?? '') & 0o777);
        self::assertStringContainsString('server.env.before-', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new BackupsSetupCommand(
            $this->runner,
            new ServerFolder($this->runner, $this->root),
            new ServerStack($this->runner, 'tcp://docker-proxy:2375', 'postgres password'),
            new BackupSettings('s3:https://old.example.com/backups', 'old', 'old secret key', 'the old password'),
            $this->root.'/cache',
            true,
        ));

        return new CommandTester($application->find('server:backups:setup'));
    }
}
