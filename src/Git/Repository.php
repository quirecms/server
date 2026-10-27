<?php

declare(strict_types=1);

namespace Quire\Server\Git;

/**
 * A Git repository a site follows, and its branch: over SSH (with the site's deploy key) or HTTPS (a
 * public repository). Nothing else: no local paths or file:// (they'd read the server's own files), no
 * git://, no "ext::" (which runs a command), nothing that could be read as an option.
 */
final readonly class Repository
{
    /** git@host:path, user@host:path */
    private const string SCP = '#^[a-z0-9][a-z0-9._-]{0,31}@[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?:[A-Za-z0-9][A-Za-z0-9._~/-]{0,250}\z#';

    /** ssh://user@host[:port]/path, https://host[:port]/path */
    private const string URL = '#^(ssh|https)://(?:[a-z0-9][a-z0-9._-]{0,31}@)?[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(?::[0-9]{1,5})?/[A-Za-z0-9][A-Za-z0-9._~/-]{0,250}\z#';

    /** A branch name Git accepts, without what could be an option or a path's way out */
    private const string BRANCH = '#^(?!-)(?!.*\.\.)(?!.*//)(?!.*@\{)(?!.*\.lock(?:/|\z))[A-Za-z0-9._/-]{1,100}(?<![./])\z#';

    public function __construct(
        public string $url,
        public string $branch = 'main',
    ) {
        if (1 !== preg_match(self::SCP, $url) && 1 !== preg_match(self::URL, $url)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a repository address this server follows: git@host:path, ssh://… or https://….', $this->printable($url)));
        }

        if (str_contains($url, '/../') || str_ends_with($url, '/..')) {
            throw new \InvalidArgumentException('A repository\'s path can\'t contain "..".');
        }

        if (1 !== preg_match(self::BRANCH, $branch)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a branch name.', $this->printable($branch)));
        }
    }

    /**
     * Whether it's reached over SSH, with the site's deploy key (HTTPS: a public repository).
     */
    public function overSsh(): bool
    {
        return !str_starts_with($this->url, 'https://');
    }

    /**
     * A commit's ID, as Git prints it (SHA-1, or SHA-256 repositories).
     */
    public static function isCommit(string $commit): bool
    {
        return 1 === preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $commit);
    }

    private function printable(string $value): string
    {
        return mb_substr((string) preg_replace('/[^\x20-\x7e]/', '?', $value), 0, 100);
    }
}
