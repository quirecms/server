<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use PHPUnit\Framework\TestCase;
use Quire\Server\Backup\RestoredKeys;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A deploy key from a server's backup, put where this server keeps keys: plain files only.
 */
final class RestoredKeysTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-restored-keys-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/backup/keys/blog/id_ed25519', 'the key');
        new Filesystem()->dumpFile($this->root.'/backup/keys/blog/id_ed25519.pub', 'ssh-ed25519 AAAA');
        // Another site's key on this server
        new Filesystem()->dumpFile($this->root.'/server/keys/jane/id_ed25519', 'jane\'s private key');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testAKeyIsPutInPlaceAsRootsOnly(): void
    {
        self::assertTrue(RestoredKeys::install($this->root.'/backup/keys/blog', $this->root.'/server/keys/blog'));

        self::assertSame('the key', file_get_contents($this->root.'/server/keys/blog/id_ed25519'));
        self::assertSame(0o700, fileperms($this->root.'/server/keys/blog') & 0o777);
        self::assertSame(0o600, fileperms($this->root.'/server/keys/blog/id_ed25519') & 0o777);
    }

    public function testALinkToAnotherKeyIsRefused(): void
    {
        unlink($this->root.'/backup/keys/blog/id_ed25519');
        symlink($this->root.'/server/keys/jane/id_ed25519', $this->root.'/backup/keys/blog/id_ed25519');

        try {
            RestoredKeys::install($this->root.'/backup/keys/blog', $this->root.'/server/keys/blog');
            self::fail('It was restored.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('"id_ed25519", which isn\'t a plain file', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->root.'/server/keys/blog', 'Nothing put in place.');
    }

    public function testAKeyFolderThatIsALinkIsntFollowed(): void
    {
        new Filesystem()->remove($this->root.'/backup/keys/blog');
        symlink($this->root.'/server/keys/jane', $this->root.'/backup/keys/blog');

        self::assertFalse(RestoredKeys::install($this->root.'/backup/keys/blog', $this->root.'/server/keys/blog'));
        self::assertFileDoesNotExist($this->root.'/server/keys/blog');
    }

    public function testAKeyThisServerHasIsKept(): void
    {
        new Filesystem()->dumpFile($this->root.'/server/keys/blog/id_ed25519', 'this server\'s');

        self::assertFalse(RestoredKeys::install($this->root.'/backup/keys/blog', $this->root.'/server/keys/blog'));
        self::assertSame('this server\'s', file_get_contents($this->root.'/server/keys/blog/id_ed25519'));
    }
}
