<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use Quire\Server\Backup\BackupLedger;
use Quire\Server\Backup\BackupSigning;
use Quire\Server\Backup\SigningKeyLost;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The server's key for its backups (D-36), as the registry records it: made once, and never made anew
 * silently when it went missing or was replaced, since other servers trust the recorded one.
 */
final class BackupSigningTest extends RegistryTestCase
{
    private string $directory;
    private BackupLedger $ledger;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/quire-signing-'.bin2hex(random_bytes(6));
        $this->ledger = new BackupLedger($this->connection, $this->schema);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testTheKeyIsMadeOnceAndRecorded(): void
    {
        $signing = $this->signing();

        self::assertTrue($signing->ensure());
        self::assertSame($signing->publicKey(), $this->ledger->signingKey());
        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->file())), -4));
        self::assertSame('0700', substr(\sprintf('%o', fileperms(\dirname($this->file()))), -4));
        self::assertFalse($signing->ensure(), 'Once.');
    }

    public function testAKeyGoneIsntMadeAnew(): void
    {
        $signing = $this->signing();
        $signing->ensure();
        $public = $signing->publicKey();
        unlink($this->file());

        try {
            $signing->ensure();
            self::fail('Another key was made.');
        } catch (SigningKeyLost $e) {
            self::assertStringContainsString('is gone', $e->getMessage());
            self::assertStringContainsString($public, $e->getMessage(), 'It names the key other servers trust.');
            self::assertStringContainsString('files.tar, signing/backups.key', $e->getMessage(), 'Where a copy is.');
            self::assertStringContainsString('quire-server backups:new-key', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->file());
        self::assertSame($public, $this->ledger->signingKey());
    }

    public function testAnotherKeyIsntSignedWith(): void
    {
        $signing = $this->signing();
        $signing->ensure();
        $recorded = $this->ledger->signingKey();
        file_put_contents($this->file(), base64_encode(sodium_crypto_sign_keypair())."\n");

        $this->expectException(SigningKeyLost::class);
        $this->expectExceptionMessageIsOrContains('isn\'t the one this server signs its backups with ('.$recorded.')');
        $signing->ensure();
    }

    public function testAKeyMadeBeforeKeysWereRecordedIsTheServersFromNowOn(): void
    {
        // Quire Server 0.4.0 made the key, without recording it
        new BackupSigning($this->file())->ensure();
        $signing = $this->signing();

        self::assertFalse($signing->ensure());
        self::assertSame($signing->publicKey(), $this->ledger->signingKey());
    }

    public function testANewKeyOnPurposeReplacesTheRecordedOne(): void
    {
        $signing = $this->signing();
        $signing->ensure();
        $old = $signing->publicKey();
        unlink($this->file());

        $new = $signing->startOver();

        self::assertNotSame($old, $new);
        self::assertSame($new, $this->ledger->signingKey());
        self::assertFalse($signing->ensure(), 'And it\'s signed with from now on.');
    }

    public function testOnlyAKeyPairIsAKey(): void
    {
        $pair = sodium_crypto_sign_keypair();

        self::assertSame('ed25519:'.base64_encode(sodium_crypto_sign_publickey($pair)), BackupSigning::publicKeyOf(base64_encode($pair)."\n"));
        self::assertNull(BackupSigning::publicKeyOf(''));
        self::assertNull(BackupSigning::publicKeyOf('not base64 !'));
        self::assertNull(BackupSigning::publicKeyOf(base64_encode(sodium_crypto_sign_publickey($pair))), 'A public key alone isn\'t one.');
    }

    private function signing(): BackupSigning
    {
        return new BackupSigning($this->file(), $this->ledger);
    }

    private function file(): string
    {
        return $this->directory.'/signing/backups.key';
    }
}
