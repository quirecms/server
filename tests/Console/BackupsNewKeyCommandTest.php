<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Console;

use Quire\Server\Backup\BackupLedger;
use Quire\Server\Backup\BackupSigning;
use Quire\Server\Console\BackupsNewKeyCommand;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A new key for the server's backups, only on purpose: what other servers then need is said.
 */
final class BackupsNewKeyCommandTest extends RegistryTestCase
{
    private string $directory;
    private BackupLedger $ledger;
    private BackupSigning $signing;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/quire-new-key-'.bin2hex(random_bytes(6));
        $this->ledger = new BackupLedger($this->connection, $this->schema);
        $this->signing = new BackupSigning($this->directory.'/signing/backups.key', $this->ledger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testAskedItChangesNothing(): void
    {
        $this->signing->ensure();
        $old = $this->ledger->signingKey();
        $tester = $this->tester();
        $tester->setInputs(['no']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('This server signs its backups with '.$old, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame($old, $this->ledger->signingKey());
    }

    public function testANewKeyIsMadeAndRecordedAndItsPublicKeyShown(): void
    {
        $this->signing->ensure();
        $old = $this->ledger->signingKey();
        // Its file lost: the night refuses to make another by itself
        unlink($this->directory.'/signing/backups.key');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--yes' => true], ['interactive' => false]));

        $new = $this->ledger->signingKey();
        self::assertNotNull($new);
        self::assertNotSame($old, $new);
        self::assertStringContainsString($new, $tester->getDisplay());
        self::assertFalse($this->signing->ensure(), 'Signed with from now on.');
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new BackupsNewKeyCommand($this->signing, $this->ledger));

        return new CommandTester($application->find('server:backups:new-key'));
    }
}
