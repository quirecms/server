<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\TestCase;
use Quire\Server\Update\Lock;
use Quire\Server\Update\PackageChecks;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The checks of what arrives (G2, G3, no plugins) on a lock Composer itself wrote, not one made by
 * hand: real "composer update", offline, from an inline package repository whose packages stand in for
 * Quire's. (Packages come from Packagist only on a site: SitePackages refuses such a repository before
 * this. Here it's only how to get Composer to write those fields.).
 */
final class ComposerLockTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quire-composer-lock-'.bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testWhatComposerWritesIsReadAndRefused(): void
    {
        $commit = str_repeat('c', 40);
        $package = static fn (string $name, string $type, array $more = []): array => [
            'name' => $name,
            'version' => '1.0.0',
            'type' => $type,
            'dist' => ['type' => 'zip', 'url' => 'https://example.com/'.$name.'.zip', 'reference' => $commit],
            ...$more,
        ];
        new Filesystem()->dumpFile($this->directory.'/composer.json', (string) json_encode([
            'require' => ['acme/impostor' => '1.0.0', 'acme/helper' => '1.0.0'],
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'package', 'package' => $package('acme/impostor', 'quire-extension', ['replace' => ['quire/core' => '*'], 'provide' => ['quire/privacy' => '0.3.1'], 'require' => ['acme/plugin' => '1.0.0']])],
                ['type' => 'package', 'package' => $package('acme/plugin', 'composer-plugin', ['require' => ['composer-plugin-api' => '^2.0']])],
                ['type' => 'package', 'package' => ['name' => 'acme/helper', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'http://example.com/helper.zip']]],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        $home = $this->directory.'/home';
        $composer = new Process(['composer', 'update', '--no-install', '--no-scripts', '--no-plugins', '--no-audit', '--no-interaction', '--no-progress'], $this->directory, ['COMPOSER_HOME' => $home, 'COMPOSER_CACHE_DIR' => $home.'/cache', 'COMPOSER_ALLOW_SUPERUSER' => '1'], timeout: 120);
        $composer->run();
        self::assertTrue($composer->isSuccessful(), $composer->getErrorOutput());

        $lock = Lock::read($this->directory.'/composer.lock');
        $impostor = $lock->details['acme/impostor'];
        self::assertSame(['quire-extension', ['quire/core'], ['quire/privacy'], $commit], [$impostor['type'], $impostor['replace'], $impostor['provide'], $impostor['dist']['reference']], 'As Composer writes them.');
        self::assertSame('library', $lock->details['acme/helper']['type'], 'No type: Composer\'s default.');

        $refused = PackageChecks::refusals(Lock::parse('{"packages": []}'), $lock, ['acme/helper', 'acme/impostor'], ['acme/helper', 'acme/impostor', 'acme/plugin']);
        self::assertSame([
            'acme/helper isn\'t a download over HTTPS',
            'acme/impostor replaces quire/core (it would stand in for it)',
            'acme/impostor provides quire/privacy (it would stand in for it)',
            'acme/plugin is a Composer plugin (its code would run in every build of the site)',
        ], $refused);
    }
}
