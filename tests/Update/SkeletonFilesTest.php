<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\TestCase;
use Quire\Server\Update\SkeletonFiles;
use Symfony\Component\Filesystem\Filesystem;

final class SkeletonFilesTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-skeleton-files-'.bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testEachFileComparedThreeWays(): void
    {
        $this->files('base', [
            'Dockerfile' => 'FROM 1',
            'compose.yaml' => 'compose 1',
            'compose.prod.yaml' => 'prod 1',
            'frankenphp/Caddyfile' => 'caddy 1',
            '.gitignore' => 'gone upstream',
            'prod.env.dist' => 'same',
        ]);
        $this->files('target', [
            'Dockerfile' => 'FROM 2',
            'compose.yaml' => 'compose 2',
            'compose.prod.yaml' => 'prod 2',
            'frankenphp/Caddyfile' => 'caddy 2',
            'frankenphp/conf.d/new.ini' => 'new',
            'prod.env.dist' => 'same',
        ]);
        $this->files('site', [
            'Dockerfile' => 'FROM 1',            // never edited: follows the new version
            'compose.yaml' => 'compose mine',     // edited, and changed upstream: a conflict
            'compose.prod.yaml' => 'prod 2',      // already as the new version
            'frankenphp/Caddyfile' => 'caddy 1',
            '.gitignore' => 'gone upstream',
            'prod.env.dist' => 'mine',            // edited, but unchanged upstream: left alone
            'templates/home.html.twig' => 'the site\'s content, never compared',
        ]);

        self::assertSame([
            ['path' => '.gitignore', 'action' => SkeletonFiles::DELETE],
            ['path' => 'Dockerfile', 'action' => SkeletonFiles::REPLACE],
            ['path' => 'compose.yaml', 'action' => SkeletonFiles::CONFLICT],
            ['path' => 'frankenphp/Caddyfile', 'action' => SkeletonFiles::REPLACE],
            ['path' => 'frankenphp/conf.d/new.ini', 'action' => SkeletonFiles::CREATE],
        ], SkeletonFiles::changes($this->root.'/site', $this->root.'/base', $this->root.'/target'));
    }

    public function testWithoutTheSkeletonItCameFromEveryDifferenceIsAConflict(): void
    {
        $this->files('target', ['Dockerfile' => 'FROM 2', 'compose.yaml' => 'same']);
        $this->files('site', ['Dockerfile' => 'FROM 1', 'compose.yaml' => 'same']);

        self::assertSame([['path' => 'Dockerfile', 'action' => SkeletonFiles::CONFLICT]], SkeletonFiles::changes($this->root.'/site', null, $this->root.'/target'));
    }

    public function testApplyingLeavesConflictsAndCanBePutBack(): void
    {
        $this->files('base', ['Dockerfile' => 'FROM 1', 'compose.yaml' => 'compose 1', '.gitignore' => 'old']);
        $this->files('target', ['Dockerfile' => 'FROM 2', 'compose.yaml' => 'compose 2', 'frankenphp/new.ini' => 'new']);
        $this->files('site', ['Dockerfile' => 'FROM 1', 'compose.yaml' => 'compose mine', '.gitignore' => 'old']);
        $site = $this->root.'/site';
        $changes = SkeletonFiles::changes($site, $this->root.'/base', $this->root.'/target');
        $applied = array_values(array_filter($changes, static fn (array $change): bool => SkeletonFiles::CONFLICT !== $change['action']));
        $paths = array_column($applied, 'path');

        SkeletonFiles::save($site, $paths, $this->root.'/saved');
        SkeletonFiles::apply($site, $this->root.'/target', $applied);

        self::assertSame('FROM 2', file_get_contents($site.'/Dockerfile'));
        self::assertSame('compose mine', file_get_contents($site.'/compose.yaml'), 'The site\'s edit kept.');
        self::assertSame('new', file_get_contents($site.'/frankenphp/new.ini'));
        self::assertFileDoesNotExist($site.'/.gitignore');

        SkeletonFiles::restore($site, $paths, $this->root.'/saved');

        self::assertSame('FROM 1', file_get_contents($site.'/Dockerfile'));
        self::assertSame('old', file_get_contents($site.'/.gitignore'));
        self::assertFileDoesNotExist($site.'/frankenphp/new.ini', 'Added by the update: removed again.');
    }

    public function testOnlyPathsInsideTheSite(): void
    {
        $this->expectExceptionMessageIsOrContains('isn\'t a path inside the site');
        SkeletonFiles::apply($this->root.'/site', $this->root.'/target', [['path' => '../../etc/passwd', 'action' => SkeletonFiles::DELETE]]);
    }

    /**
     * @param array<string, string> $files
     */
    private function files(string $folder, array $files): void
    {
        foreach ($files as $path => $content) {
            new Filesystem()->dumpFile($this->root.'/'.$folder.'/'.$path, $content);
        }
    }
}
