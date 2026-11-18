<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Site;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Site\SiteFiles;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Where a site's stored files are, for a restore as a new site: its disks outside it, as its
 * quire:about says, or as its code says for a Quire that doesn't.
 */
final class StorageOutsideTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quire-storage-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->directory.'/composer.lock', '{"packages": [{"name": "league/flysystem", "version": "3.30.0"}]}');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testFromItsReport(): void
    {
        self::assertSame(['the disk "photos" (s3: jane-files at https://s3.example.com)', 'the disk "app" (service)'], SiteFiles::storageOutside([
            ['name' => 'media', 'adapter' => 'local', 'location' => 'local: /app/storage/public/media'],
            ['name' => 'photos', 'adapter' => 's3', 'location' => 's3: jane-files at https://s3.example.com'],
            ['name' => 'app', 'adapter' => 'service', 'location' => null],
            'not a disk',
        ]));
        self::assertSame([], SiteFiles::storageOutside([]));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function configurations(): iterable
    {
        yield 'YAML, on S3' => ['config/packages/quire.yaml', "quire:\n    storage:\n        disks:\n            photos:\n                adapter: s3\n", 'config/packages/quire.yaml has a disk on "s3"'];
        yield 'YAML, quoted' => ['config/packages/prod/quire.yaml', "            photos: { adapter: 'service', service: app.files }\n", 'config/packages/prod/quire.yaml has a disk on "service"'];
        yield 'PHP' => ['config/packages/quire.php', "<?php return ['quire' => ['storage' => ['disks' => ['photos' => ['adapter' => 's3']]]]];\n", 'config/packages/quire.php has a disk on "s3"'];
        yield 'chosen by the environment' => ['config/packages/quire.yaml', "                adapter: '%env(STORAGE_ADAPTER)%'\n", 'config/packages/quire.yaml has a disk on "%env"'];
        yield 'local' => ['config/packages/quire.yaml', "                adapter: local\n", null];
        yield 'no disks' => ['config/packages/framework.yaml', "framework:\n    secret: '%env(APP_SECRET)%'\n", null];
    }

    #[DataProvider('configurations')]
    public function testFromItsCode(string $file, string $content, ?string $found): void
    {
        new Filesystem()->dumpFile($this->directory.'/'.$file, $content);

        self::assertSame(null === $found ? [] : [$found], SiteFiles::storageOutsideInCode($this->directory));
    }

    public function testAnAdapterForRemoteStorageInItsLock(): void
    {
        file_put_contents($this->directory.'/composer.lock', (string) json_encode(['packages' => array_map(static fn (string $name): array => ['name' => $name, 'version' => '3.29.0'], [
            'league/flysystem', 'league/flysystem-local', 'league/flysystem-memory', 'league/flysystem-async-aws-s3', 'league/flysystem-sftp-v3', 'league/flysystem-azure-blob-storage',
        ])], \JSON_PRETTY_PRINT));

        self::assertSame([
            'its composer.lock has league/flysystem-async-aws-s3, an adapter for storage outside the site',
            'its composer.lock has league/flysystem-sftp-v3, an adapter for storage outside the site',
            'its composer.lock has league/flysystem-azure-blob-storage, an adapter for storage outside the site',
        ], SiteFiles::storageOutsideInCode($this->directory), 'Any Flysystem adapter but the local and memory ones (R2).');
    }
}
