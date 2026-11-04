<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use PHPUnit\Framework\TestCase;
use Quire\Server\Backup\BackupFailed;
use Quire\Server\Backup\BackupSigning;
use Quire\Server\Backup\Provenance;
use Symfony\Component\Filesystem\Filesystem;

final class ProvenanceTest extends TestCase
{
    private string $root;
    private BackupSigning $signing;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-provenance-'.bin2hex(random_bytes(6));
        $this->signing = new BackupSigning($this->root.'/signing/backups.key');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testTheKeyIsMadeOnceAndOnlyRootReadsIt(): void
    {
        self::assertFalse($this->signing->exists());
        self::assertTrue($this->signing->ensure());
        $key = $this->signing->publicKey();
        self::assertFalse($this->signing->ensure(), 'Never replaced: backups signed with it would no longer check out.');
        self::assertSame($key, $this->signing->publicKey());
        self::assertSame(0o600, fileperms($this->root.'/signing/backups.key') & 0o777);
        self::assertSame(0o700, fileperms($this->root.'/signing') & 0o777);
        self::assertMatchesRegularExpression('#^ed25519:[A-Za-z0-9+/]{43}=\z#', $key);
        self::assertSame([], glob($this->root.'/signing/backups.key.*'), 'No temporary file left.');
    }

    public function testADamagedKeyIsSaid(): void
    {
        new Filesystem()->dumpFile($this->root.'/signing/backups.key', "not a key\n");

        $this->expectException(BackupFailed::class);
        $this->signing->sign('x');
    }

    public function testASignedListVouchesForItsSnapshots(): void
    {
        $this->signing->ensure();
        $document = Provenance::document('jane-server', $this->records(), $this->signing, new \DateTimeImmutable('2026-11-04T02:30:00Z'));

        self::assertSame([str_repeat('a', 64) => ['kind' => 'site', 'site' => 'jane', 'created_at' => '2026-11-04T02:00:00+00:00'], str_repeat('b', 64) => ['kind' => 'server', 'site' => null, 'created_at' => '2026-11-04T02:05:00+00:00']], Provenance::verify($document, 'jane-server', [$this->signing->publicKey()]));
    }

    public function testAListIsOnlyTheServersItWasSignedFor(): void
    {
        $this->signing->ensure();
        $document = Provenance::document('jane-server', $this->records(), $this->signing, new \DateTimeImmutable());

        $this->expectExceptionMessageIsOrContains('is signed, but not for it');
        Provenance::verify($document, 'kim-server', [$this->signing->publicKey()]);
    }

    public function testAChangedListDoesntCheckOut(): void
    {
        $this->signing->ensure();
        $document = Provenance::document('jane-server', $this->records(), $this->signing, new \DateTimeImmutable());
        $outer = json_decode($document, true, 4, \JSON_THROW_ON_ERROR);
        self::assertIsArray($outer);
        self::assertIsString($outer['signed']);
        // A snapshot of someone's added to the list
        $outer['signed'] = str_replace(str_repeat('b', 64), str_repeat('e', 64), $outer['signed']);

        $this->expectExceptionMessageIsOrContains('isn\'t signed by its key');
        Provenance::verify((string) json_encode($outer), 'jane-server', [$this->signing->publicKey()]);
    }

    public function testOnlyTheKeysGivenCount(): void
    {
        $this->signing->ensure();
        $someone = new BackupSigning($this->root.'/someone/backups.key');
        $someone->ensure();
        // Signed with its own key, which it names: that it checks out with that key proves nothing
        $document = Provenance::document('jane-server', $this->records(), $someone, new \DateTimeImmutable());

        $this->expectException(BackupFailed::class);
        Provenance::verify($document, 'jane-server', [$this->signing->publicKey()]);
    }

    public function testKeysAndSignaturesThatArentOnes(): void
    {
        self::assertFalse(BackupSigning::valid('ed25519:short'));
        self::assertFalse(BackupSigning::valid(base64_encode(str_repeat('k', 32))), 'Its algorithm named.');
        $this->signing->ensure();
        self::assertTrue(BackupSigning::verify('message', $this->signing->sign('message'), $this->signing->publicKey()));
        self::assertFalse(BackupSigning::verify('message', 'not base64!', $this->signing->publicKey()));
        self::assertFalse(BackupSigning::verify('other message', $this->signing->sign('message'), $this->signing->publicKey()));
    }

    /**
     * @return array<string, array{id: string, host: string, kind: string, site: ?string, created_at: string}>
     */
    private function records(): array
    {
        return [
            str_repeat('a', 64) => ['id' => str_repeat('a', 64), 'host' => 'jane-server', 'kind' => 'site', 'site' => 'jane', 'created_at' => '2026-11-04T02:00:00+00:00'],
            str_repeat('b', 64) => ['id' => str_repeat('b', 64), 'host' => 'jane-server', 'kind' => 'server', 'site' => null, 'created_at' => '2026-11-04T02:05:00+00:00'],
        ];
    }
}
