<?php

declare(strict_types=1);

namespace Quire\Server\Git;

use Quire\Server\Process\ProcessRunner;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\SiteName;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A site's deploy key: an SSH key of its own (Ed25519) that the repository's owner adds as a read-only
 * deploy key, so the server can read that one repository and nothing else. It's kept in
 * /srv/quire/server/keys/<site> (root only), outside the site's folder: it can never be committed or
 * built into an image. So is the hosts' keys the site's repository was first reached with.
 */
final readonly class DeployKeys
{
    private const string KEY = 'id_ed25519';
    private const string KNOWN_HOSTS = 'known_hosts';

    public function __construct(
        private ProcessRunner $runner,
        private ServerPaths $paths,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * The site's public key, created first when it has none.
     */
    public function publicKey(SiteName $site, string $comment): string
    {
        $key = $this->key($site);

        if (!is_file($key)) {
            $this->filesystem->mkdir(\dirname($key), 0o700);
            chmod(\dirname($key), 0o700);
            $this->runner->run(
                ['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $this->comment($comment), '-f', $key],
                timeout: 60,
            )->orFail('Creating the deploy key');
            chmod($key, 0o600);
        }

        $public = @file_get_contents($key.'.pub');

        return false === $public ? throw new \RuntimeException('The deploy key has no public part.') : trim($public);
    }

    public function exists(SiteName $site): bool
    {
        return is_file($this->key($site));
    }

    /**
     * The key and the hosts the site trusts, gone (a removed site's).
     */
    public function remove(SiteName $site): void
    {
        $this->filesystem->remove(\dirname($this->key($site)));
    }

    /**
     * How Git reaches the site's repository over SSH: with its key only, never asking anything, and
     * trusting a host's key the first time (then only that key, for this site).
     */
    public function sshCommand(SiteName $site): string
    {
        $key = $this->key($site);

        return implode(' ', [
            'ssh',
            '-i', escapeshellarg($key),
            '-o', 'IdentitiesOnly=yes',
            '-o', 'IdentityAgent=none',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile='.escapeshellarg(\dirname($key).'/'.self::KNOWN_HOSTS),
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=20',
            '-F', '/dev/null',
        ]);
    }

    private function key(SiteName $site): string
    {
        return $this->paths->keys($site).'/'.self::KEY;
    }

    private function comment(string $comment): string
    {
        return mb_substr((string) preg_replace('/[^A-Za-z0-9@._:-]/', '-', $comment), 0, 100);
    }
}
