<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use Quire\Server\Backup\LocalBackups;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The backups the server keeps of its sites on itself, on the registry's real tables: kept while
 * they can be undone, then removed, but each site's newest and a removed site's final one.
 */
final class LocalBackupsTest extends RegistryTestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-local-backups-'.bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testOldBackupsGoButTheNewestAndTheFinalOneStay(): void
    {
        $files = [
            'jane/deploy-20261001-120000.tar', 'jane/deploy-20261001-120000.database.dump',
            'jane/update-20261002-120000.tar', 'jane/update-20261002-120000/files/composer.lock',
            'jane/deploy-20261025-120000.tar',
            'kim/update-20261001-120000.tar', 'kim/update-20261001-120000.database.dump',
            'gone/final-20260901-120000.tar',
            'server/20261001-120000/server.env',
            '.restic-cache/x',
        ];

        foreach ($files as $file) {
            new Filesystem()->dumpFile($this->root.'/'.$file, 'x');
        }

        $removed = $this->subject()->expire('cli', 'darko');

        sort($removed);
        self::assertSame(['jane/deploy-20261001-120000.database.dump', 'jane/deploy-20261001-120000.tar', 'jane/update-20261002-120000', 'jane/update-20261002-120000.tar'], $removed);
        self::assertFileExists($this->root.'/jane/deploy-20261025-120000.tar', 'Within two weeks.');
        self::assertFileExists($this->root.'/kim/update-20261001-120000.tar', 'Its newest: kept, however old.');
        self::assertFileExists($this->root.'/kim/update-20261001-120000.database.dump', 'With its dump.');
        self::assertFileExists($this->root.'/gone/final-20260901-120000.tar', 'A removed site\'s last backup.');
        self::assertFileExists($this->root.'/server/20261001-120000/server.env', 'Not a site\'s.');
        self::assertFalse($this->subject()->due(), 'Once a day.');
    }

    private function subject(): LocalBackups
    {
        return new LocalBackups(new OperationLog($this->connection, $this->schema), $this->root, now: static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-30 21:00'));
    }
}
