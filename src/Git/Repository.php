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
    /** The host Git connects to, and its port (null: the protocol's own) */
    public string $host;
    public ?int $port;
    /** git@host:path, user@host:path */
    private const string SCP = '#^[a-z0-9][a-z0-9._-]{0,31}@[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?:[A-Za-z0-9][A-Za-z0-9._~/-]{0,250}\z#';

    /** ssh://user@host[:port]/path, https://host[:port]/path (no user, nor a token, in an HTTPS address) */
    private const string URL = '#^(?:ssh://(?:[a-z0-9][a-z0-9._-]{0,31}@)?|https://)([a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::([0-9]{1,5}))?/[A-Za-z0-9][A-Za-z0-9._~/-]{0,250}\z#';

    /**
     * A branch name Git accepts (git check-ref-format --branch), without what could be an option or a
     * path's way out: no part starting with "." and not "HEAD".
     */
    private const string BRANCH = '#^(?!-)(?!HEAD\z)(?!\.)(?!.*/\.)(?!.*\.\.)(?!.*//)(?!.*@\{)(?!.*\.lock(?:/|\z))[A-Za-z0-9._/-]{1,100}(?<![./])\z#';

    public function __construct(
        public string $url,
        public string $branch = 'main',
    ) {
        if (1 === preg_match(self::URL, $url, $match)) {
            [$host, $port] = [$match[1], $match[2] ?? ''];
        } elseif (1 === preg_match(self::SCP, $url, $match)) {
            [$host, $port] = [explode(':', explode('@', $url, 2)[1], 2)[0], ''];
        } else {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a repository address this server follows: git@host:path, ssh://… or https://… (with no token in it: a public repository, or a deploy key).', $this->printable($url)));
        }

        if ('' !== $port && ((int) $port < 1 || (int) $port > 65535)) {
            throw new \InvalidArgumentException(\sprintf('"%s": a port is 1 to 65535.', $this->printable($url)));
        }

        $this->host = strtolower($host);
        $this->port = '' === $port ? null : (int) $port;

        // A host on the internet, not one of this machine's: no single-label name (the server's own
        // containers: quire-database…), no private, loopback or link-local address (cloud metadata)
        if (!str_contains($host, '.') || (false !== filter_var($host, \FILTER_VALIDATE_IP) && false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE))) {
            throw new \InvalidArgumentException(\sprintf('"%s": a repository is on a host on the internet, not on this machine or its networks.', $this->printable($url)));
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
     * The address with its host replaced by one of its IP addresses, for SSH (which then checks the
     * host's key by its name: HostKeyAlias).
     */
    public function at(string $address): string
    {
        $literal = str_contains($address, ':') ? '['.$address.']' : $address;

        return str_starts_with($this->url, 'ssh://')
            ? (string) preg_replace('#^(ssh://(?:[^@/]+@)?)[^:/]+#', '${1}'.$literal, $this->url)
            : (string) preg_replace('#^([^@]+@)[^:]+#', '${1}'.$literal, $this->url);
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
