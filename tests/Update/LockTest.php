<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\TestCase;
use Quire\Server\Update\Lock;

final class LockTest extends TestCase
{
    public function testWhatChangesBetweenTwoLocks(): void
    {
        $before = self::lock(['quire/core' => 'v0.1.0', 'symfony/yaml' => 'v8.1.0', 'old/gone' => '1.0.0']);
        $after = self::lock(['quire/core' => 'v0.2.0', 'symfony/yaml' => 'v8.1.0', 'new/added' => '2.0.0']);

        self::assertSame('v0.1.0', $before->quire());
        self::assertSame([
            ['package' => 'new/added', 'from' => null, 'to' => '2.0.0'],
            ['package' => 'old/gone', 'from' => '1.0.0', 'to' => null],
            ['package' => 'quire/core', 'from' => 'v0.1.0', 'to' => 'v0.2.0'],
        ], $before->changesTo($after));
        self::assertSame([], $after->changesTo($after));
    }

    public function testALockThatIsntOneIsRefused(): void
    {
        $this->expectExceptionMessageIsOrContains('has no packages');
        Lock::parse('{"content-hash": "x"}');
    }

    public function testWhatEachPackageIsAndWhereItComesFrom(): void
    {
        $lock = self::packages([
            self::package('acme/reviews', '1.2.0', 'quire-extension', ['quire/core' => '^0.3', 'acme/reviews-core' => '^1.0', 'php' => '>=8.5', 'ext-intl' => '*'], replace: ['acme/old-reviews' => 'self.version'], provide: ['psr/log-implementation' => '3.0']),
            self::package('acme/reviews-core', '1.0.4', require: ['psr/log' => '^3.0']),
            self::package('acme/fancy', '2.0.0', 'quire-theme', extra: ['quire' => ['id' => 'fancy', 'theme' => ['parent' => 'quire-theme-default']]]),
            self::package('quire/core', 'v0.3.1', 'symfony-bundle', ['psr/log' => '^3.0']),
            self::package('psr/log', '3.0.2'),
            ['name' => 'old/bare', 'version' => '1.0.0'],
        ]);

        $reviews = $lock->details['acme/reviews'];
        self::assertSame(['quire-extension', ['acme/reviews-core', 'ext-intl', 'php', 'quire/core'], ['acme/old-reviews'], ['psr/log-implementation']], [$reviews['type'], $reviews['require'], $reviews['replace'], $reviews['provide']]);
        self::assertSame(['url' => 'https://api.github.com/repos/acme/reviews/zipball/'.self::commit('acme/reviews', '1.2.0'), 'reference' => self::commit('acme/reviews', '1.2.0')], $reviews['dist']);
        self::assertSame(['acme-reviews', null], [$reviews['id'], $reviews['parent']], 'Its id as Quire derives it from its name.');
        self::assertSame(['fancy', 'quire-theme-default'], [$lock->details['acme/fancy']['id'], $lock->details['acme/fancy']['parent']], 'Or as it names it.');
        self::assertSame(['library', null, null], [$lock->details['old/bare']['type'], $lock->details['old/bare']['dist']['url'], $lock->details['old/bare']['dist']['reference']], 'Composer\'s defaults.');

        // What it needs, through what those need; platform packages aren't in a lock.
        self::assertSame(['acme/reviews', 'acme/reviews-core', 'psr/log', 'quire/core'], array_keys($lock->needs(['acme/reviews'])));
        self::assertSame([], $lock->needs(['nobody/there']));
    }

    public function testTheSameVersionAsOtherCode(): void
    {
        $before = self::packages([self::package('acme/reviews', '1.2.0'), self::package('psr/log', '3.0.2'), ['name' => 'old/bare', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://example.com/bare.git', 'reference' => 'aaa']]]);
        $after = self::packages([self::package('acme/reviews', '1.2.0', commit: str_repeat('b', 40)), self::package('psr/log', '3.0.3'), ['name' => 'old/bare', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://example.com/bare.git', 'reference' => 'bbb']]]);

        self::assertSame([
            ['package' => 'acme/reviews', 'version' => '1.2.0', 'from' => self::commit('acme/reviews', '1.2.0'), 'to' => str_repeat('b', 40)],
            ['package' => 'old/bare', 'version' => '1.0.0', 'from' => 'aaa', 'to' => 'bbb'],
        ], $before->republished($after), 'By its download\'s commit, or its source\'s without one; a new version is a change, not this.');
        self::assertSame([], $before->republished($before));
    }

    /**
     * @param array<string, string> $require
     * @param array<string, string> $replace
     * @param array<string, string> $provide
     * @param array<string, mixed>  $extra
     *
     * @return array<string, mixed> A package as a composer.lock has it, from GitHub over HTTPS
     */
    public static function package(string $name, string $version, string $type = 'library', array $require = [], array $replace = [], array $provide = [], array $extra = [], ?string $commit = null): array
    {
        $commit ??= self::commit($name, $version);

        return array_filter([
            'name' => $name,
            'version' => $version,
            'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => $commit],
            'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/'.$name.'/zipball/'.$commit, 'reference' => $commit, 'shasum' => ''],
            'type' => $type,
            'require' => $require,
            'replace' => $replace,
            'provide' => $provide,
            'extra' => $extra,
        ], static fn (mixed $value): bool => [] !== $value);
    }

    /**
     * The commit a release of a package is, here.
     */
    public static function commit(string $name, string $version): string
    {
        return hash('sha1', $name.' '.$version);
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    public static function packages(array $packages): Lock
    {
        return Lock::parse(json_encode(['content-hash' => 'x', 'packages' => $packages, 'packages-dev' => []], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, string> $versions
     */
    public static function lock(array $versions): Lock
    {
        $packages = [];

        foreach ($versions as $name => $version) {
            $packages[] = ['name' => $name, 'version' => $version];
        }

        return Lock::parse(json_encode(['packages' => $packages, 'packages-dev' => []], \JSON_THROW_ON_ERROR));
    }
}
