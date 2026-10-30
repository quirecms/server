<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Docker\ComposeFiles;
use Quire\Server\Docker\PolicyContext;
use Quire\Server\Docker\SiteProject;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A site's Compose files as they are on disk, before Compose reads (and resolves) them: only its own.
 */
final class ComposeFilesTest extends TestCase
{
    private string $root;
    private string $site;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-compose-files-'.bin2hex(random_bytes(6));
        $this->site = $this->root.'/sites/jane';
        new Filesystem()->dumpFile($this->site.'/compose.yaml', "services:\n  php:\n    image: jane-php\n  worker:\n    extends: php\n");
        new Filesystem()->dumpFile($this->site.'/compose.prod.yaml', "services:\n  php:\n    environment:\n      APP_SECRET: \${APP_SECRET}\n");
        new Filesystem()->dumpFile($this->site.'/compose.server.yaml', "services:\n  php:\n    ports: !reset null\n");
        new Filesystem()->dumpFile($this->root.'/sites/other/prod.env', "QUIRE_DATABASE_PASSWORD='the other site\\'s'\n");
        $this->env('compose.yaml:compose.prod.yaml:compose.server.yaml');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testASitesOwnFilesPass(): void
    {
        self::assertSame([], $this->violations(), 'Including "extends" of a service of the same file, and Compose\'s own tags.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostile(): iterable
    {
        yield 'another site\'s secrets, through include' => ["include:\n  - path: compose.yaml\n    env_file: ../other/prod.env\n", '"include" reads other files'];
        yield 'another site\'s Compose file' => ["include:\n  - ../other/compose.yaml\n", '"include" reads other files'];
        yield 'a service of another file' => ["services:\n  php:\n    extends:\n      file: ../other/compose.yaml\n      service: php\n", 'extends one of another file'];
        yield 'not YAML the server reads' => ["services: [\n", 'isn\'t YAML the server can read'];
    }

    #[DataProvider('hostile')]
    public function testWhatReadsOtherFilesIsRefused(string $prod, string $expected): void
    {
        file_put_contents($this->site.'/compose.prod.yaml', $prod);

        self::assertStringContainsString($expected, implode("\n", $this->violations()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outside(): iterable
    {
        yield 'a parent folder' => ['compose.yaml:../other/compose.yaml'];
        yield 'an absolute path' => ['compose.yaml:/srv/quire/sites/other/compose.yaml'];
        yield 'an empty entry' => ['compose.yaml::compose.server.yaml'];
    }

    #[DataProvider('outside')]
    public function testComposeFilesOutsideTheFolderAreRefused(string $files): void
    {
        $this->env($files);

        self::assertStringContainsString('(COMPOSE_FILE) isn\'t a file of the site\'s folder', implode("\n", $this->violations()));
    }

    public function testALinkOutOfTheFolderIsRefused(): void
    {
        symlink($this->root.'/sites/other/prod.env', $this->site.'/compose.extra.yaml');
        $this->env('compose.yaml:compose.extra.yaml');

        self::assertStringContainsString('"compose.extra.yaml" (COMPOSE_FILE)', implode("\n", $this->violations()));
    }

    private function env(string $files): void
    {
        file_put_contents($this->site.'/prod.env', "COMPOSE_FILE='".$files."'\nCOMPOSE_PROJECT_NAME='jane'\n");
    }

    /**
     * @return list<string>
     */
    private function violations(): array
    {
        return new ComposeFiles()->violations(new SiteProject($this->site, $this->site.'/prod.env', new PolicyContext('jane', $this->site, [])));
    }
}
