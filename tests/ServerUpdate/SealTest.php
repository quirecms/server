<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerUpdate;

use PHPUnit\Framework\TestCase;
use Quire\Server\ServerUpdate\ServerBackup;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The updater's own records, in a folder the agent can write too (HOST-13): only those it sealed are
 * taken, once it has sealed any.
 */
final class SealTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-seal-'.bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testABackupTheUpdaterSealedIsTakenAndAChangedOneIsnt(): void
    {
        $backup = $this->backup('20261104-100000-000000');
        $backup->save();

        self::assertNotNull(ServerBackup::load($backup->directory));
        self::assertSame(0o600, fileperms($this->root.'/server/.updater-key') & 0o777, 'Its key: root\'s only, where the agent doesn\'t reach.');

        // The agent changes its server's files: e.g. a compose.yaml with Docker's socket
        file_put_contents($backup->files(), 'planted');
        self::assertNull(ServerBackup::load($backup->directory));
    }

    public function testOnePlantedBesideIsntTakenOnceAnyIsSealed(): void
    {
        $planted = $this->backup('20261104-120000-000000');
        file_put_contents($planted->directory.'/backup.json', (string) json_encode(['from' => '0.4.0', 'to' => '0.5.0', 'migrations' => [], 'created_at' => '2026-11-04T12:00:00+00:00']));
        self::assertNotNull(ServerBackup::load($planted->directory), 'Before any seal (the update that brought this release): taken.');

        $this->backup('20261104-100000-000000')->save();
        self::assertNull(ServerBackup::load($planted->directory), 'Once the updater seals: one without a seal isn\'t its own.');
    }

    private function backup(string $name): ServerBackup
    {
        $directory = $this->root.'/backups/server/'.$name;
        new Filesystem()->mkdir($directory);
        file_put_contents($directory.'/files.tar', 'files');
        file_put_contents($directory.'/database.dump', 'dump');

        return new ServerBackup($directory, '0.4.0', '0.5.0', [], new \DateTimeImmutable('2026-11-04T10:00:00Z'));
    }
}
