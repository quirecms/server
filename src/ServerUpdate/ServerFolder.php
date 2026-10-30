<?php

declare(strict_types=1);

namespace Quire\Server\ServerUpdate;

use Quire\Server\Process\Durable;
use Quire\Server\Process\ProcessRunner;
use Quire\Server\Site\EnvFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The server's own folder (/srv/quire/server): a release's files, and what's the machine's own and
 * stays from one release to the next (its settings and secrets, the front door's domains, Git mode
 * sites' deploy keys).
 */
final readonly class ServerFolder
{
    /** The machine's own: never replaced by a release, nor taken from one */
    public const array KEPT = ['server.env', 'domains', 'keys'];

    public string $path;

    public function __construct(
        private ProcessRunner $runner,
        string $root,
        private Filesystem $filesystem = new Filesystem(),
    ) {
        $this->path = rtrim($root, '/').'/server';
    }

    public function env(): EnvFile
    {
        return EnvFile::read($this->path.'/server.env');
    }

    /**
     * Writes server.env (root's only), all at once: it's never half written.
     */
    public function writeEnv(EnvFile $env): void
    {
        self::writeSecret($this->path.'/server.env', $env->content());
    }

    /**
     * A copy of the release's files and of server.env, in a tar file.
     */
    public function archive(string $file): void
    {
        $this->runner->run(['tar', '-cf', $file, '-C', $this->path, ...$this->releaseEntries(), 'server.env'], timeout: 300)->orFail('Backing up the server\'s files');
        chmod($file, 0o600);
        Durable::sync($file);
    }

    /**
     * The release's files replaced with another release's (its own files, as extracted): what's kept
     * stays, and is never taken from the release.
     */
    public function replace(string $release): void
    {
        $this->removeReleaseEntries();

        $entries = array_values(array_filter($this->entries($release), static fn (string $entry): bool => !\in_array($entry, self::KEPT, true)));

        if ([] !== $entries) {
            // Copied (it may be on another file system), modes kept, owned by root.
            $this->runner->run(['cp', '-R', '-p', ...array_map(static fn (string $entry): string => $release.'/'.$entry, $entries), $this->path.'/'], timeout: 300)->orFail('Copying the release\'s files');
        }

        $this->settle();
    }

    /**
     * The files of a copy made by archive(), server.env included, as they were.
     */
    public function restore(string $file): void
    {
        $this->removeReleaseEntries();
        $this->runner->run(['tar', '-xf', $file, '-C', $this->path, '--no-same-owner'], timeout: 300)->orFail('Putting the server\'s files back');
        chmod($this->path.'/server.env', 0o600);
        $this->settle();
    }

    public static function writeSecret(string $path, string $content): void
    {
        $temporary = $path.'.new';
        $previous = umask(0o077);

        try {
            if (false === file_put_contents($temporary, $content)) {
                throw new \RuntimeException(\sprintf('%s can\'t be written.', $temporary));
            }
        } finally {
            umask($previous);
        }

        chmod($temporary, 0o600);
        Durable::sync($temporary);
        rename($temporary, $path);
    }

    /**
     * @return list<string> What in the folder is the release's
     */
    private function releaseEntries(): array
    {
        return array_values(array_filter($this->entries($this->path), static fn (string $entry): bool => !\in_array($entry, self::KEPT, true) && !str_starts_with($entry, 'server.env.')));
    }

    private function removeReleaseEntries(): void
    {
        foreach ($this->releaseEntries() as $entry) {
            $this->filesystem->remove($this->path.'/'.$entry);
        }
    }

    /**
     * As install.sh leaves it: root's, writable by root only.
     */
    private function settle(): void
    {
        $this->runner->run(['chmod', '-R', 'go-w', ...array_map(fn (string $entry): string => $this->path.'/'.$entry, $this->releaseEntries())], timeout: 60)->orFail('Setting the server\'s files\' permissions');
    }

    /**
     * @return list<string> The names in a folder (hidden ones too)
     */
    private function entries(string $folder): array
    {
        $entries = scandir($folder);

        if (false === $entries) {
            throw new \RuntimeException(\sprintf('The folder %s can\'t be read.', $folder));
        }

        return array_values(array_diff($entries, ['.', '..']));
    }
}
