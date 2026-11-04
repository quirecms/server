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
        // The audit's (ISO-12, FUZZ-10)
        yield 'a token as the user of an HTTPS address' => ['https://token@github.com/a/b'];
        yield 'the server\'s PostgreSQL' => ['ssh://git@quire-database:5432/x'];
        yield 'the server\'s own containers' => ['git@quire-agent:repo'];
        yield 'cloud metadata' => ['https://169.254.169.254/latest/meta-data'];
        yield 'a private address' => ['ssh://git@10.0.0.5/team/site.git'];
        yield 'loopback' => ['https://127.0.0.1/me/blog.git'];
        yield 'a port that isn\'t one' => ['ssh://git@git.example.com:99999/x'];
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
        yield 'hidden' => ['.hidden'];
        yield 'a hidden part' => ['a/.b'];
        yield 'HEAD' => ['HEAD'];
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

    public function testItsHostIsPinnedToAnAddress(): void
    {
        self::assertSame('ssh://git@140.82.121.4/me/blog.git', new Repository('ssh://git@github.com/me/blog.git')->at('140.82.121.4'));
        self::assertSame('ssh://[2606:50c0::1]:2222/me/blog.git', new Repository('ssh://git.example.com:2222/me/blog.git')->at('2606:50c0::1'));
        self::assertSame('git@140.82.121.4:me/blog.git', new Repository('git@github.com:me/blog.git')->at('140.82.121.4'));
        self::assertSame('github.com', new Repository('git@github.com:me/blog.git')->host);
        self::assertSame(2222, new Repository('ssh://git.example.com:2222/me/blog.git')->port);
        self::assertNull(new Repository('https://github.com/me/blog.git')->port);
    }
}
