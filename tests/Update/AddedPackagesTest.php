<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\TestCase;
use Quire\Server\Update\AddedPackages;

/**
 * The record of what an owner added (in the site's composer.json), and what it holds in updates (G1).
 */
final class AddedPackagesTest extends TestCase
{
    public function testTheRecordChangesNothingElseOfComposerJson(): void
    {
        $json = <<<'JSON'
            {
                "require": {"quire/core": "^0.3"},
                "require-dev": {},
                "config": {"allow-plugins": {"symfony/flex": true}},
                "extra": {"symfony": {"require": "8.1.*"}}
            }
            JSON;

        $with = AddedPackages::with($json, ['quire/analytics' => false, 'acme/reviews' => true]);
        self::assertStringContainsString('"require-dev": {}', $with, 'An empty object stays one (Composer refuses a list there).');
        self::assertSame(['acme/reviews' => true, 'quire/analytics' => false], AddedPackages::of(AddedPackages::read($this->file($with))));
        $decoded = json_decode($with, true);
        self::assertIsArray($decoded);
        self::assertSame(['symfony' => ['require' => '8.1.*'], 'quire-server' => ['packages' => ['acme/reviews' => ['third-party' => true], 'quire/analytics' => ['third-party' => false]]]], $decoded['extra']);

        // The last one gone: nothing of the record is left.
        $without = AddedPackages::with($with, []);
        self::assertSame(json_decode($json, true), json_decode($without, true));
        self::assertStringNotContainsString('quire-server', AddedPackages::with('{"require": {}}', []));
    }

    public function testADamagedRecordHoldsPackagesWhereTheyAre(): void
    {
        $added = AddedPackages::of(['extra' => ['quire-server' => ['packages' => [
            'quire/analytics' => 'yes',
            'quire/privacy' => ['third-party' => false],
            'acme/reviews' => ['third-party' => false],
            '--no-plugins' => ['third-party' => false],
        ]]]]);

        self::assertSame(['acme/reviews' => true, 'quire/analytics' => true, 'quire/privacy' => false], $added, 'Only a package of Quire\'s name, marked so, is Quire\'s; what isn\'t a package\'s name is no package.');
    }

    public function testThirdPartyPackagesAndWhatOnlyTheyNeedAreHeld(): void
    {
        $lock = LockTest::packages([
            LockTest::package('quire/core', 'v0.3.1', 'symfony-bundle', ['psr/log' => '^3.0']),
            LockTest::package('psr/log', '3.0.2'),
            LockTest::package('acme/reviews', '1.2.0', 'quire-extension', ['quire/core' => '^0.3', 'acme/reviews-core' => '^1.0', 'acme/shared' => '^1.0']),
            LockTest::package('acme/reviews-core', '1.0.4', require: ['psr/log' => '^3.0']),
            LockTest::package('acme/shared', '1.1.0'),
            LockTest::package('beta/gallery', '0.4.0', 'quire-extension', ['acme/shared' => '^1.0']),
            LockTest::package('quire/analytics', 'v0.3.1', 'quire-extension', ['quire/core' => '^0.3']),
        ]);
        $json = [
            'require' => ['php' => '>=8.5', 'quire/core' => '^0.3', 'acme/reviews' => '^1.2', 'beta/gallery' => '^0.4', 'quire/analytics' => '^0.3'],
            'extra' => ['quire-server' => ['packages' => ['acme/reviews' => ['third-party' => true], 'beta/gallery' => ['third-party' => true], 'quire/analytics' => ['third-party' => false]]]],
        ];

        self::assertSame(['acme/reviews' => '1.2.0', 'acme/reviews-core' => '1.0.4', 'acme/shared' => '1.1.0', 'beta/gallery' => '0.4.0'], AddedPackages::held($json, $lock), 'Not quire/*, nor psr/log: Quire needs them too, and they move with it.');
        self::assertSame(['acme/shared' => '1.1.0', 'beta/gallery' => '0.4.0'], AddedPackages::held($json, $lock, 'acme/reviews'), 'Moving acme/reviews: what only it needs moves with it, what beta/gallery needs too stays.');
        self::assertSame([], AddedPackages::held(['require' => $json['require']], $lock), 'Nothing added: nothing held.');
    }

    private function file(string $content): string
    {
        $file = sys_get_temp_dir().'/quire-added-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($file, $content);
        register_shutdown_function(static fn (): bool => @unlink($file));

        return $file;
    }
}
