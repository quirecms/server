<?php

declare(strict_types=1);

namespace Quire\Server\Git;

use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;
use Quire\Server\Site\SiteFiles;
use Quire\Server\Site\SiteName;

/**
 * Git, for sites that follow a repository: a checkout of their branch in their folder, moved to
 * exactly the commit being deployed. Every call is fenced: SSH and HTTPS only, no system or user
 * configuration, no hooks, no prompts, symbolic links checked out as plain files (a repository can't
 * point the site's build at the server's files), no submodules.
 */
final readonly class Git
{
    /** The server's files in a site's folder, which the repository never has (or overwrites) */
    private const array SERVER_FILES = ['/'.SiteFiles::ENV_FILE, '/'.SiteFiles::ENV_FILE.'.*', '/'.SiteFiles::OVERRIDE];

    public function __construct(
        private ProcessRunner $runner,
        private DeployKeys $keys,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $output
     */
    public function clone(Repository $repository, SiteName $site, string $directory, ?\Closure $output = null): void
    {
        if (file_exists($directory)) {
            throw new \DomainException(\sprintf('"%s" exists already.', $directory));
        }

        $this->git($repository, $site, null, [
            'clone', '--branch', $repository->branch, '--single-branch', '--no-recurse-submodules', '--no-tags',
            // Kept in the checkout's own configuration, for every later call
            '--config', 'core.symlinks=false',
            '--config', 'core.hooksPath=/dev/null',
            '--config', 'core.fsmonitor=false',
            '--config', 'submodule.recurse=false',
            '--', $repository->url, $directory,
        ], $output)->orFail('Getting the site\'s repository (git clone)');

        chmod($directory, 0o750);
        // The server's files in the folder: never seen by Git, never removed by a deploy.
        file_put_contents($directory.'/.git/info/exclude', "\n# Quire Server's files\n".implode("\n", self::SERVER_FILES)."\n", \FILE_APPEND);
    }

    /**
     * The commit the branch points to now, in the repository (without fetching anything).
     */
    public function remoteHead(Repository $repository, SiteName $site): string
    {
        $result = $this->git($repository, $site, null, ['ls-remote', '--heads', '--', $repository->url, 'refs/heads/'.$repository->branch])
            ->orFail('Asking the repository for its branch (git ls-remote)');
        $commit = strtok(trim($result->output), "\t");

        return \is_string($commit) && Repository::isCommit($commit)
            ? $commit
            : throw new \DomainException(\sprintf('The repository has no branch "%s".', $repository->branch));
    }

    /**
     * Fetches the branch and makes the folder exactly that commit (the branch's newest when null):
     * tracked files as the commit has them, files the repository doesn't have removed, the server's
     * own files kept.
     *
     * @param (\Closure(string): void)|null $output
     *
     * @return string The commit checked out
     */
    public function checkout(Repository $repository, SiteName $site, string $directory, ?string $commit = null, ?\Closure $output = null): string
    {
        if (null !== $commit && !Repository::isCommit($commit)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a commit ID (all of its 40 characters).', mb_substr($commit, 0, 80)));
        }

        $this->git($repository, $site, $directory, ['fetch', '--no-tags', '--no-recurse-submodules', '--', $repository->url, '+refs/heads/'.$repository->branch.':refs/remotes/origin/'.$repository->branch], $output)
            ->orFail('Getting the repository\'s new commits (git fetch)');
        $target = $commit ?? $this->revision($repository, $site, $directory, 'refs/remotes/origin/'.$repository->branch);

        $this->moveTo($repository, $site, $directory, $target, $output);

        return $target;
    }

    /**
     * Makes the folder exactly a commit it has already (e.g. the one before a deploy, to go back).
     *
     * @param (\Closure(string): void)|null $output
     */
    public function moveTo(Repository $repository, SiteName $site, string $directory, string $commit, ?\Closure $output = null): void
    {
        if (!Repository::isCommit($commit)) {
            throw new \InvalidArgumentException('That isn\'t a commit ID.');
        }

        // Only commits of the branch: not anything else the folder's Git could name.
        $this->git($repository, $site, $directory, ['merge-base', '--is-ancestor', $commit, 'refs/remotes/origin/'.$repository->branch])
            ->orFail(\sprintf('Finding commit %s on the branch "%s"', substr($commit, 0, 12), $repository->branch));
        $this->git($repository, $site, $directory, ['reset', '--hard', '--quiet', $commit], $output)->orFail('Checking out the commit');
        $this->git($repository, $site, $directory, ['clean', '-ffdq'], $output)->orFail('Removing files the commit doesn\'t have');
    }

    public function head(Repository $repository, SiteName $site, string $directory): string
    {
        return $this->revision($repository, $site, $directory, 'HEAD');
    }

    private function revision(Repository $repository, SiteName $site, string $directory, string $name): string
    {
        $commit = trim($this->git($repository, $site, $directory, ['rev-parse', '--verify', '--quiet', $name.'^{commit}'])->orFail('Reading the commit')->output);

        return Repository::isCommit($commit) ? $commit : throw new \RuntimeException('Git printed no commit.');
    }

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output
     */
    private function git(Repository $repository, SiteName $site, ?string $directory, array $arguments, ?\Closure $output = null): ProcessResult
    {
        $env = [
            'GIT_ALLOW_PROTOCOL' => 'ssh:https',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => '/bin/false',
            'SSH_ASKPASS' => '/bin/false',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_SSH_COMMAND' => $repository->overSsh() ? $this->keys->sshCommand($site) : false,
            // Nothing inherited redirects Git elsewhere.
            'GIT_DIR' => false,
            'GIT_WORK_TREE' => false,
            'GIT_SSH' => false,
            'GIT_PROXY_COMMAND' => false,
            'GIT_EXEC_PATH' => false,
            'GIT_TEMPLATE_DIR' => false,
        ];

        return $this->runner->run(
            ['git', ...(null === $directory ? [] : ['-C', $directory]), '-c', 'core.hooksPath=/dev/null', '-c', 'core.symlinks=false', '-c', 'protocol.file.allow=never', ...$arguments],
            $env,
            timeout: 900,
            output: $output,
        );
    }
}
