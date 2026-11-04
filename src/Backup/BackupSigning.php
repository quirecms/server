<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

/**
 * The server's own key for its backups off the server (D-36): Ed25519, kept in a file only root reads,
 * outside server.env and outside everything the server backs up. Whoever has server.env (and so the
 * repository's password) can write snapshots, but not sign them. Its public key is the owner's to keep
 * with the repository's password: restoring on another machine checks it.
 */
final readonly class BackupSigning
{
    /** How a public key is written: its algorithm, then the key in base64 */
    private const string PREFIX = 'ed25519:';

    public function __construct(
        private string $file,
    ) {
    }

    /**
     * Makes the key when there's none: once per server.
     *
     * @return bool Whether it was made now
     */
    public function ensure(): bool
    {
        if (is_file($this->file)) {
            return false;
        }

        $directory = \dirname($this->file);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Couldn\'t create "%s".', $directory));
        }

        chmod($directory, 0o700);
        $temporary = $this->file.'.'.bin2hex(random_bytes(4));
        $previous = umask(0o077);

        try {
            if (false === file_put_contents($temporary, base64_encode(sodium_crypto_sign_keypair())."\n")) {
                throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $temporary));
            }

            // Never two keys: if another process made one meanwhile, its is kept
            if (!@link($temporary, $this->file) && !is_file($this->file)) {
                throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $this->file));
            }
        } finally {
            @unlink($temporary);
            umask($previous);
        }

        return true;
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /** The public key, as the owner writes it down ("ed25519:…") */
    public function publicKey(): string
    {
        return self::PREFIX.base64_encode(sodium_crypto_sign_publickey($this->pair()));
    }

    /**
     * @return string The signature, in base64
     */
    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($this->pair())));
    }

    /**
     * Whether the signature is the key's, of the message.
     */
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        $key = self::key($publicKey);
        $signature = base64_decode($signature, true);

        return null !== $key && false !== $signature && \SODIUM_CRYPTO_SIGN_BYTES === \strlen($signature) && sodium_crypto_sign_verify_detached($signature, $message, $key);
    }

    /**
     * Whether it's a public key as the server writes them.
     */
    public static function valid(string $publicKey): bool
    {
        return null !== self::key($publicKey);
    }

    /**
     * @return non-empty-string|null The key's bytes
     */
    private static function key(string $publicKey): ?string
    {
        if (!str_starts_with($publicKey, self::PREFIX)) {
            return null;
        }

        $key = base64_decode(substr($publicKey, \strlen(self::PREFIX)), true);

        return false !== $key && \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === \strlen($key) ? $key : null;
    }

    /**
     * @return non-empty-string
     */
    private function pair(): string
    {
        $pair = is_file($this->file) && !is_link($this->file) ? base64_decode(trim((string) file_get_contents($this->file)), true) : false;

        if (false === $pair || \SODIUM_CRYPTO_SIGN_KEYPAIRBYTES !== \strlen($pair)) {
            throw new BackupFailed(\sprintf('The server\'s key for its backups (%s) is missing or damaged.', $this->file));
        }

        return $pair;
    }
}
