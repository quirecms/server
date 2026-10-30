<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Git;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Git\Repository;

final class RepositoryTest extends TestCase
{
    public function testRepositoriesOverSshAndHttps(): void
    {
        foreach (['git@github.com:me/blog.git', 'deploy@git.example.com:team/site', 'ssh://git@git.example.com:2222/team/site.git', 'https://github.com/quirecms/skeleton.git'] as $url) {
            self::assertSame($url, new Repository($url, 'main')->url);
        }

        self::assertTrue(new Repository('git@github.com:me/blog.git')->overSsh());
        self::assertFalse(new Repository('https://github.com/me/blog.git')->overSsh());
        self::assertSame('release/2026-10', new Repository('git@github.com:me/blog.git', 'release/2026-10')->branch);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatArentRepositories(): iterable
    {
        yield 'a local path (the server\'s own files)' => ['/srv/quire/server'];
        yield 'file://' => ['file:///srv/quire/server/keys'];
        yield 'git://, unencrypted' => ['git://github.com/me/blog.git'];
        yield 'ext::, which runs a command' => ['ext::sh -c touch% /tmp/pwned'];
        yield 'an option' => ['--upload-pack=touch /tmp/pwned'];
        yield 'an option as the user' => ['-oProxyCommand=touch /tmp/pwned@host:path'];
        yield 'http, unencrypted' => ['http://github.com/me/blog.git'];
        yield 'a way out of the path' => ['https://github.com/me/../../etc'];
        yield 'credentials in the address' => ['https://user:token@github.com/me/blog.git'];
        yield 'a line break' => ["git@github.com:me/blog.git\n--upload-pack=x"];
        yield 'a space' => ['git@github.com:me/blog .git'];
    }

    #[DataProvider('addressesThatArentRepositories')]
    public function testAnythingElseIsRefused(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Repository($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatArentBranches(): iterable
    {
        yield 'an option' => ['--orphan'];
        yield 'a way up' => ['../main'];
        yield 'a reflog' => ['main@{1}'];
        yield 'a lock file' => ['main.lock'];
        yield 'a line break' => ["main\nx"];
        yield 'empty' => [''];
        yield 'a trailing slash' => ['main/'];
    }

    #[DataProvider('namesThatArentBranches')]
    public function testBranchNamesAreChecked(string $branch): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Repository('git@github.com:me/blog.git', $branch);
    }

    public function testCommitIds(): void
    {
        self::assertTrue(Repository::isCommit(str_repeat('a', 40)));
        self::assertTrue(Repository::isCommit(str_repeat('b', 64)));
        self::assertFalse(Repository::isCommit('HEAD'));
        self::assertFalse(Repository::isCommit(str_repeat('a', 12)));
        self::assertFalse(Repository::isCommit(str_repeat('a', 40)."\n"));
        self::assertFalse(Repository::isCommit('--upload-pack='.str_repeat('a', 40)), 'An option Git would read.');
        self::assertFalse(Repository::isCommit(str_repeat('a', 40).'b'));
    }
}
