<?php

declare(strict_types=1);

namespace Quire\Server\ServerUpdate;

/**
 * What the updater saves for itself in a folder the agent can write too (HOST-13: its backups in
 * /srv/quire/backups/server, a PostgreSQL upgrade's record in /srv/quire/backups/database), sealed
 * with a key only the updater reads (/srv/quire/server, which the agent doesn't mount): an HMAC of each
 * file's hash. The updater holds Docker's socket; without the seal, an agent taken over could plant a
 * "backup" whose compose.yaml "update --undo" would then install.
 *
 * Before the key exists (no update made it yet), there's nothing sealed to compare with: a record
 * without a seal is taken then (one an older release made). Once it exists, it isn't.
 */
final readonly class Seal
{
    public const string FILE = '.seal';

    public function __construct(
        private string $key,
    ) {
    }

    public static function of(string $root): self
    {
        return new self(rtrim($root, '/').'/server/.updater-key');
    }

    /**
     * @param list<string> $files Names in the folder
     */
    public function seal(string $directory, array $files): void
    {
        $key = $this->key(true) ?? throw new \LogicException('The updater\'s key was just made.');
        file_put_contents($directory.'/'.self::FILE, $this->mac($directory, $files, $key)."\n");
        chmod($directory.'/'.self::FILE, 0o600);
    }

    /**
     * Whether what's in the folder is what the updater sealed (or, before any seal, has none).
     *
     * @param list<string> $files
     */
    public function holds(string $directory, array $files): bool
    {
        $key = $this->key(false);
        $seal = is_file($directory.'/'.self::FILE) && !is_link($directory.'/'.self::FILE) ? trim((string) file_get_contents($directory.'/'.self::FILE)) : null;

        // No key: taken only if it was never sealed (an older release's). A sealed one without the
        // key that sealed it can't be checked, so it isn't taken.
        if (null === $key) {
            return null === $seal;
        }

        return null !== $seal && hash_equals($this->mac($directory, $files, $key), $seal);
    }

    /**
     * @param list<string> $files
     */
    private function mac(string $directory, array $files, string $key): string
    {
        sort($files);
        $hashes = '';

        foreach ($files as $file) {
            $path = $directory.'/'.$file;
            $hashes .= $file.' '.(is_file($path) && !is_link($path) ? (string) hash_file('sha256', $path) : 'missing')."\n";
        }

        return hash_hmac('sha256', $hashes, $key);
    }

    private function key(bool $make): ?string
    {
        if (!is_file($this->key)) {
            if (!$make) {
                return null;
            }

            if (!is_dir(\dirname($this->key)) && !mkdir(\dirname($this->key), 0o755, true) && !is_dir(\dirname($this->key))) {
                throw new \RuntimeException(\sprintf('Couldn\'t create "%s".', \dirname($this->key)));
            }

            $previous = umask(0o077);

            try {
                if (false === file_put_contents($this->key, bin2hex(random_bytes(32))."\n", \LOCK_EX)) {
                    throw new \RuntimeException(\sprintf('Couldn\'t write the updater\'s key (%s).', $this->key));
                }
            } finally {
                umask($previous);
            }
        }

        $key = trim((string) file_get_contents($this->key));

        return 64 === \strlen($key) ? $key : throw new \RuntimeException(\sprintf('The updater\'s key (%s) is damaged.', $this->key));
    }
}
