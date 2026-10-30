<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerDatabase;

use PHPUnit\Framework\TestCase;
use Quire\Server\ServerDatabase\PostgresImages;
use Symfony\Component\Filesystem\Filesystem;

final class PostgresImagesTest extends TestCase
{
    public const string PG18 = 'postgres:18-alpine@sha256:10a5c69a83035ab5239f5620d708520e8b28f5aa1f2b20143e6024532edadfb5';
    public const string PG19 = 'postgres:19-alpine@sha256:2222222222222222222222222222222222222222222222222222222222222222';

    private string $folder;
    private Filesystem $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/quire-server-images-'.bin2hex(random_bytes(6));
        $this->filesystem = new Filesystem();
        self::release($this->folder, [18 => self::PG18, 19 => self::PG19]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove($this->folder);
    }

    /**
     * A release's files as far as PostgreSQL goes: the first major is compose.yaml's.
     *
     * @param array<int, string> $images
     */
    public static function release(string $folder, array $images): void
    {
        $filesystem = new Filesystem();
        $default = array_key_first($images);

        foreach ($images as $major => $image) {
            $filesystem->dumpFile($major === $default ? $folder.'/compose.yaml' : \sprintf('%s/database/postgres-%d.yaml', $folder, $major), \sprintf("services:\n  database:\n    image: %s\n", $image));
        }
    }

    public function testTheMajorsAReleaseRuns(): void
    {
        $images = PostgresImages::of($this->folder);

        self::assertSame(18, $images->default);
        self::assertSame([18, 19], $images->majors());
        self::assertSame(self::PG19, $images->image(19));
        self::assertTrue($images->supports(18));
        self::assertFalse($images->supports(17));
    }

    public function testComposeFileNamesTheMajorOnlyWhenItIsNotTheDefault(): void
    {
        $images = PostgresImages::of($this->folder);

        self::assertSame('compose.yaml', $images->composeFile('compose.yaml', 18));
        self::assertSame('compose.yaml:database/postgres-19.yaml', $images->composeFile('compose.yaml', 19));
        // Another major's file is replaced; the server's other files stay, in their order.
        self::assertSame('compose.yaml:compose.build.yaml:database/postgres-19.yaml', $images->composeFile('compose.yaml:compose.build.yaml', 19));
        self::assertSame('compose.yaml:compose.build.yaml', $images->composeFile('compose.yaml:database/postgres-19.yaml:compose.build.yaml', 18));
        self::assertSame('compose.yaml:database/postgres-19.yaml', $images->composeFile('compose.yaml:database/postgres-19.yaml', 19));
    }

    public function testAMajorTheReleaseDoesNotRunIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('doesn\'t run PostgreSQL 20');

        PostgresImages::of($this->folder)->composeFile('compose.yaml', 20);
    }

    public function testAReleaseWithoutOtherMajorsRunsItsDefault(): void
    {
        $this->filesystem->remove($this->folder.'/database');

        self::assertSame([18], PostgresImages::of($this->folder)->majors());
    }

    public function testImagesArePinnedByDigest(): void
    {
        self::release($this->folder, [18 => self::PG18, 19 => 'postgres:19-alpine']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('postgres-19.yaml doesn\'t name a PostgreSQL image pinned by digest');

        PostgresImages::of($this->folder);
    }

    public function testAFileNamesItsOwnMajor(): void
    {
        self::release($this->folder, [18 => self::PG18, 19 => str_replace(':19-', ':20-', self::PG19)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('database/postgres-19.yaml names PostgreSQL 20');

        PostgresImages::of($this->folder);
    }

    public function testOnlyPostgresImagesCount(): void
    {
        self::release($this->folder, [18 => 'mypostgres:18@sha256:'.str_repeat('a', 64)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('compose.yaml doesn\'t name a PostgreSQL image');

        PostgresImages::of($this->folder);
    }

    public function testTheServersComposeFilesNameTheirMajors(): void
    {
        // This repository's own: the default in compose.yaml, the others in database/
        $images = PostgresImages::of(\dirname(__DIR__, 2));

        self::assertSame(18, $images->default);
        self::assertSame('compose.yaml', $images->composeFile('compose.yaml', 18));
    }
}
