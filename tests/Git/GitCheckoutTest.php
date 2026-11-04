<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Git;

use PHPUnit\Framework\TestCase;
use Quire\Server\Git\DeployKeys;
use Quire\Server\Git\Git;
use Quire\Server\Git\Repository;
use Quire\Server\Process\SymfonyProcessRunner;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\SiteName;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Moving a site's folder to a commit, with the real Git: exactly the commit's files, symbolic links
 * as plain files, the server's own files kept.
 */
final class GitCheckoutTest extends TestCase
{
    private string $root;
    private string $site;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-git-'.bin2hex(random_bytes(6));
        $this->site = $this->root.'/sites/blog';
        new Filesystem()->mkdir($this->site);
        $this->git('init', '--quiet', '--initial-branch=main');
        $this->git('config', 'user.email', 'dev@example.com');
        $this->git('config', 'user.name', 'Dev');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testTheFolderBecomesExactlyTheCommit(): void
    {
        file_put_contents($this->site.'/Dockerfile', 'FROM one');
        file_put_contents($this->site.'/old.txt', 'removed in the second commit');
        $first = $this->commit('one');

        unlink($this->site.'/old.txt');
        file_put_contents($this->site.'/Dockerfile', 'FROM two');
        // A repository pointing the build at another site's key.
        symlink('/srv/quire/server/keys/other/id_ed25519', $this->site.'/leak');
        $second = $this->commit('two');
        $this->git('update-ref', 'refs/remotes/origin/main', $second);
        $this->git('checkout', '--quiet', '--detach', $first);

        // The server's files, excluded as a clone excludes them, and what's ignored (var/)
        file_put_contents($this->site.'/.git/info/exclude', "/prod.env\n/compose.server.yaml\n/var/\n", \FILE_APPEND);
        file_put_contents($this->site.'/prod.env', "APP_SECRET='keep me'\n");
        file_put_contents($this->site.'/compose.server.yaml', 'services: {}');
        mkdir($this->site.'/var');
        file_put_contents($this->site.'/var/cache.php', '<?php // ignored');

        $git = $this->subject();
        $git->moveTo($this->repository(), new SiteName('blog'), $this->site, $second);

        self::assertSame($second, $git->head($this->repository(), new SiteName('blog'), $this->site));
        self::assertSame('FROM two', file_get_contents($this->site.'/Dockerfile'));
        self::assertFileDoesNotExist($this->site.'/old.txt');
        self::assertFalse(is_link($this->site.'/leak'), 'A symbolic link is checked out as a plain file.');
        self::assertSame('/srv/quire/server/keys/other/id_ed25519', file_get_contents($this->site.'/leak'));
        self::assertSame("APP_SECRET='keep me'\n", file_get_contents($this->site.'/prod.env'), 'The server\'s files kept.');
        self::assertFileExists($this->site.'/compose.server.yaml');
    }

    public function testChangesMadeInTheFolderArentLost(): void
    {
        file_put_contents($this->site.'/Dockerfile', 'FROM one');
        file_put_contents($this->site.'/README.md', 'as committed');
        $first = $this->commit('one');
        file_put_contents($this->site.'/Dockerfile', 'FROM two');
        $second = $this->commit('two');
        $this->git('update-ref', 'refs/remotes/origin/main', $second);
        $this->git('checkout', '--quiet', '--detach', $first);
        // Edited and added by hand (the audit's OWNER-13): a deploy used to delete both
        file_put_contents($this->site.'/README.md', 'edited on the server');
        file_put_contents($this->site.'/NOTES.txt', 'written on the server');

        try {
            $this->subject()->moveTo($this->repository(), new SiteName('blog'), $this->site, $second);
            self::fail('They were deleted.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('has changes its repository doesn\'t (', $e->getMessage());
            self::assertStringContainsString('NOTES.txt', $e->getMessage());
            self::assertStringContainsString('README.md', $e->getMessage());
        }

        self::assertSame('written on the server', file_get_contents($this->site.'/NOTES.txt'));
        self::assertSame($first, $this->subject()->head($this->repository(), new SiteName('blog'), $this->site), 'Nothing checked out.');
    }

    public function testACommitTooBigForASiteIsntCheckedOut(): void
    {
        file_put_contents($this->site.'/Dockerfile', 'FROM one');
        $first = $this->commit('one');
        // A "tree bomb" (ISO-16): one small file, in more entries than a site's folder takes
        $blob = trim($this->git('hash-object', '-w', $this->site.'/Dockerfile'));
        $entries = '';

        for ($i = 0; $i <= Git::MAX_FILES; ++$i) {
            $entries .= "100644 blob {$blob}\tf{$i}\n";
        }

        $mktree = new Process(['git', '-C', $this->site, 'mktree'], input: $entries);
        $mktree->mustRun();
        $second = trim($this->git('commit-tree', trim($mktree->getOutput()), '-p', $first, '-m', 'bomb'));
        $this->git('update-ref', 'refs/remotes/origin/main', $second);

        try {
            $this->subject()->moveTo($this->repository(), new SiteName('blog'), $this->site, $second);
            self::fail('It was checked out.');
        } catch (\DomainException $e) {
            self::assertStringContainsString(\sprintf('has %d files', Git::MAX_FILES + 1), $e->getMessage());
        }

        self::assertFileDoesNotExist($this->site.'/f1');
    }

    public function testOnlyCommitsOfTheBranch(): void
    {
        file_put_contents($this->site.'/a', 'a');
        $first = $this->commit('one');
        $this->git('update-ref', 'refs/remotes/origin/main', $first);
        $this->git('checkout', '--quiet', '-b', 'elsewhere');
        file_put_contents($this->site.'/a', 'b');
        $elsewhere = $this->commit('not on the branch');

        $this->expectExceptionMessageIsOrContains('on the branch "main"');
        $this->subject()->moveTo($this->repository(), new SiteName('blog'), $this->site, $elsewhere);
    }

    public function testACommitWithTheServersFilesIsRefused(): void
    {
        file_put_contents($this->site.'/Dockerfile', 'FROM one');
        file_put_contents($this->site.'/prod.env.dist', "APP_SECRET=\n");
        $first = $this->commit('one');
        // Its own compose.server.yaml: no hardening, no limits
        file_put_contents($this->site.'/compose.server.yaml', 'services: {php: {cap_drop: !reset []}}');
        $second = $this->commit('two');
        $this->git('update-ref', 'refs/remotes/origin/main', $second);
        $this->git('checkout', '--quiet', '--detach', $first);
        file_put_contents($this->site.'/.git/info/exclude', "/prod.env\n/compose.server.yaml\n", \FILE_APPEND);
        file_put_contents($this->site.'/compose.server.yaml', 'services: {php: {cap_drop: [ALL]}}');

        try {
            $this->subject()->moveTo($this->repository(), new SiteName('blog'), $this->site, $second);
            self::fail('It was checked out.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('has compose.server.yaml, the server\'s own files', $e->getMessage());
        }

        self::assertSame('services: {php: {cap_drop: [ALL]}}', file_get_contents($this->site.'/compose.server.yaml'), 'The server\'s file as it was.');
        self::assertSame($first, $this->subject()->head($this->repository(), new SiteName('blog'), $this->site));
    }

    private function subject(): Git
    {
        $runner = new SymfonyProcessRunner();

        return new Git($runner, new DeployKeys($runner, new ServerPaths($this->root)), new \Quire\Server\Git\GitHosts(resolve: static fn (): array => ['140.82.121.4']));
    }

    private function repository(): Repository
    {
        return new Repository('https://git.example.com/me/blog.git', 'main');
    }

    private function commit(string $message): string
    {
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '-m', $message);

        return trim($this->git('rev-parse', 'HEAD'));
    }

    private function git(string ...$arguments): string
    {
        $process = new Process(['git', '-C', $this->site, '-c', 'core.symlinks=true', ...$arguments]);
        $process->mustRun();

        return $process->getOutput();
    }
}
