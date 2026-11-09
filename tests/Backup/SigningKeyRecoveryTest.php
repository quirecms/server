<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use Quire\Server\Backup\BackupLedger;
use Quire\Server\Backup\BackupSigning;
use Quire\Server\Backup\SigningKeyRecovery;
use Quire\Server\Process\SymfonyProcessRunner;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The key Quire Server 0.4.0's updates removed (its swap took signing/ for a release's file, after
 * backing it up with them), put back at the agent's start from those backups.
 */
final class SigningKeyRecoveryTest extends RegistryTestCase
{
    private string $root;
    private BackupLedger $ledger;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-recovery-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/server', 0o755, true);
        $this->ledger = new BackupLedger($this->connection, $this->schema);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testTheKeyA040UpdateRemovedIsPutBack(): void
    {
        $key = $this->key();
        // 0.4.0's update: its backup of the server's files has the key, then the swap removes it
        $archive = $this->updateOf040('20261109-193000-000001', $key);

        self::assertSame($archive, $this->recovery()->recover());
        self::assertSame($key, file_get_contents($this->file()));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->file())), -4));
        self::assertSame('0700', substr(\sprintf('%o', fileperms(\dirname($this->file()))), -4));

        // And the night signs with it, as the server's (recorded from now on), not with a new one
        $signing = new BackupSigning($this->file(), $this->ledger);
        self::assertFalse($signing->ensure());
        self::assertSame(BackupSigning::publicKeyOf($key), $this->ledger->signingKey());
    }

    public function testTheNewestUpdatesBackupWithAKeyIsTaken(): void
    {
        $this->updateOf040('20261101-010000-000001', $old = $this->key());
        $newest = $this->updateOf040('20261109-193000-000001', $key = $this->key());
        // A later update's backup without it (the key was made after it): passed over
        $this->updateOf040('20261109-200000-000001', null);

        self::assertSame($newest, $this->recovery()->recover());
        self::assertSame($key, file_get_contents($this->file()));
        self::assertNotSame($old, $key);
    }

    public function testOnlyTheRecordedKeyIsPutBack(): void
    {
        $this->updateOf040('20261109-193000-000001', $this->key());
        $this->updateOf040('20261101-010000-000001', $recorded = $this->key());
        $this->ledger->recordSigningKey((string) BackupSigning::publicKeyOf($recorded));

        $this->recovery()->recover();

        self::assertSame($recorded, file_get_contents($this->file()), 'Another key, newer or not, isn\'t this server\'s.');
    }

    public function testWhatIsntAKeyIsntPutBack(): void
    {
        $this->updateOf040('20261109-193000-000001', "not a key\n");

        self::assertNull($this->recovery()->recover());
        self::assertFileDoesNotExist($this->file());
    }

    public function testAKeyThatsThereIsLeftAlone(): void
    {
        $this->updateOf040('20261109-193000-000001', $this->key());
        mkdir(\dirname($this->file()), 0o700);
        file_put_contents($this->file(), $mine = $this->key());

        self::assertNull($this->recovery()->recover());
        self::assertSame($mine, file_get_contents($this->file()));
    }

    public function testWithoutBackupsNothingHappens(): void
    {
        self::assertNull($this->recovery()->recover());
        self::assertFileDoesNotExist($this->file());
    }

    /**
     * An update's backup of the server's files, as 0.4.0 made it (ServerFolder::archive: the release's
     * entries, signing/ among them, and server.env).
     *
     * @return string Its files.tar
     */
    private function updateOf040(string $time, ?string $key): string
    {
        $files = $this->root.'/staging-'.$time;
        mkdir($files.'/gate', 0o755, true);
        file_put_contents($files.'/server.env', "SECRET=x\n");
        file_put_contents($files.'/gate/yorvogate.yaml', "sockets: []\n");
        $entries = ['gate', 'server.env'];

        if (null !== $key) {
            mkdir($files.'/signing', 0o700);
            file_put_contents($files.'/signing/backups.key', $key);
            $entries[] = 'signing';
        }

        $directory = $this->root.'/backups/server/'.$time;
        mkdir($directory, 0o700, true);
        $archive = $directory.'/files.tar';
        new SymfonyProcessRunner()->run(['tar', '-cf', $archive, '-C', $files, ...$entries])->orFail('Making the backup');
        new Filesystem()->remove($files);

        return $archive;
    }

    private function recovery(): SigningKeyRecovery
    {
        return new SigningKeyRecovery(new SymfonyProcessRunner(), $this->file(), $this->root.'/backups/server', $this->ledger);
    }

    private function key(): string
    {
        return base64_encode(sodium_crypto_sign_keypair())."\n";
    }

    private function file(): string
    {
        return $this->root.'/server/signing/backups.key';
    }
}
