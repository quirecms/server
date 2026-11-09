<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Quire\Server\Process\ProcessRunner;

/**
 * Puts back the key this server signs its backups with, when an update of the server removed it.
 *
 * Quire Server 0.4.0's updates took signing/ for one of the release's files: they backed it up with
 * them (the server's backup of the update, files.tar) and removed it. Its next night would have made
 * another key, which other servers don't trust. So at the agent's start, a missing key is taken from
 * the newest of those backups that has one: a key pair, and the one the registry has when it has one.
 * Those backups are root's, and sealed by the updater, whose seal key the agent doesn't hold: what's
 * taken is checked for what it is instead.
 */
final readonly class SigningKeyRecovery
{
    /** Its path in an update's backup of the server's files (ServerFolder::archive) */
    private const string ENTRY = 'signing/backups.key';

    public function __construct(
        private ProcessRunner $runner,
        private string $file,
        private string $serverBackups,
        private BackupLedger $ledger,
    ) {
    }

    /**
     * @return string|null The backup it was taken from, or null when nothing was put back (it's there,
     *                     or none of the backups has it)
     */
    public function recover(): ?string
    {
        if (is_file($this->file) || is_link($this->file)) {
            return null;
        }

        $recorded = $this->ledger->signingKey();
        $found = glob($this->serverBackups.'/*/files.tar');
        $archives = array_values(array_filter(false === $found ? [] : $found, static fn (string $path): bool => is_file($path) && !is_link($path) && !is_link(\dirname($path))));
        // Named by their time (Ymd-His-u): the newest first
        rsort($archives, \SORT_STRING);

        foreach ($archives as $archive) {
            $read = $this->runner->run(['tar', '-xOf', $archive, self::ENTRY], timeout: 60);
            $key = $read->successful() ? BackupSigning::publicKeyOf($read->output) : null;

            if (null === $key || (null !== $recorded && $recorded !== $key)) {
                continue;
            }

            $this->write($read->output);

            return $archive;
        }

        return null;
    }

    private function write(#[\SensitiveParameter] string $content): void
    {
        $directory = \dirname($this->file);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Couldn\'t create "%s".', $directory));
        }

        chmod($directory, 0o700);
        $temporary = $this->file.'.'.bin2hex(random_bytes(4));
        $previous = umask(0o077);

        try {
            if (false === file_put_contents($temporary, trim($content)."\n")) {
                throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $temporary));
            }

            // Never over a key that's there: one made meanwhile is kept
            if (!@link($temporary, $this->file) && !is_file($this->file)) {
                throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $this->file));
            }
        } finally {
            @unlink($temporary);
            umask($previous);
        }
    }
}
