<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;

/**
 * restic, on the repository the settings name: backups are encrypted on the server (AES-256, with the
 * repository's password) before they leave it, stored once per unique chunk (a night adds only what
 * changed), kept as the retention says, and verified. Its keys and password go in its environment,
 * never in its arguments.
 */
final readonly class Restic
{
    public const string SNAPSHOT = '/^(?:latest|[0-9a-f]{8,64})\z/';

    public function __construct(
        private ProcessRunner $runner,
        private BackupSettings $settings,
        private string $cache,
    ) {
    }

    /**
     * Whether the repository exists and opens with the password: true; it doesn't exist: false.
     *
     * @throws BackupFailed When it exists but doesn't open (another password), or can't be reached
     */
    public function opens(): bool
    {
        $result = $this->restic(['cat', 'config'], 120);

        if ($result->successful()) {
            return true;
        }

        // restic's exit code 10: the repository doesn't exist
        if (10 === $result->exitCode) {
            return false;
        }

        throw new BackupFailed(12 === $result->exitCode ? 'The backup repository doesn\'t open with this password.' : 'The backup repository can\'t be reached: '.$this->reason($result));
    }

    public function init(): void
    {
        $this->orFail($this->restic(['init'], 300), 'Creating the backup repository');
    }

    /**
     * Backs up files and folders, tagged, but what the exclusions match; returns its snapshot's ID.
     *
     * @param list<string> $paths
     * @param list<string> $tags
     * @param list<string> $exclude Absolute paths, e.g. a site's vendor folder
     */
    public function backup(array $paths, array $tags, array $exclude = []): string
    {
        $excluded = [];

        foreach ($exclude as $path) {
            if (!str_starts_with($path, '/') || str_contains($path, '..')) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t an absolute path.', $path));
            }

            $excluded[] = '--exclude='.$path;
        }

        $result = $this->orFail($this->restic(['backup', '--json', '--host', $this->settings->host, ...$this->tags($tags), ...$excluded, '--', ...$paths], 7200), 'Backing up off the server');

        foreach (array_reverse(explode("\n", trim($result->output))) as $line) {
            $message = json_decode($line, true);

            if (\is_array($message) && 'summary' === ($message['message_type'] ?? null) && \is_string($message['snapshot_id'] ?? null)) {
                return $message['snapshot_id'];
            }
        }

        throw new BackupFailed('restic didn\'t say which snapshot it made.');
    }

    /**
     * A server's snapshots (this one's, or another's in the same repository), oldest first, of those
     * with all these tags.
     *
     * @param list<string> $tags
     *
     * @return list<array{id: string, time: string, tags: list<string>, paths: list<string>}>
     */
    public function snapshots(array $tags = [], ?string $host = null): array
    {
        if (null !== $host && 1 !== preg_match('/^[a-z0-9][a-z0-9.-]{0,62}\z/', $host)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a server\'s name in the backups.', $host));
        }

        $result = $this->orFail($this->restic(['snapshots', '--json', '--host', $host ?? $this->settings->host, ...([] === $tags ? [] : ['--tag', implode(',', $tags)])], 300), 'Listing the backups');
        $snapshots = json_decode($result->output, true);
        $list = [];

        foreach (\is_array($snapshots) ? $snapshots : [] as $snapshot) {
            if (!\is_array($snapshot) || !\is_string($snapshot['id'] ?? null) || !\is_string($snapshot['time'] ?? null)) {
                continue;
            }

            $list[] = [
                'id' => $snapshot['id'],
                'time' => $snapshot['time'],
                'tags' => array_values(array_filter(\is_array($snapshot['tags'] ?? null) ? $snapshot['tags'] : [], is_string(...))),
                'paths' => array_values(array_filter(\is_array($snapshot['paths'] ?? null) ? $snapshot['paths'] : [], is_string(...))),
            ];
        }

        return $list;
    }

    /**
     * Restores one file of a snapshot into a folder (made here); returns where it is.
     */
    public function restoreFile(string $snapshot, string $path, string $into): string
    {
        $this->restorePaths($snapshot, [$path], $into);
        $file = $into.$path;

        return is_file($file) ? $file : throw new BackupFailed('The snapshot doesn\'t have its backup file.');
    }

    /**
     * Forgets the snapshots the retention doesn't keep (each site's, and the server's, on their own),
     * and, with $prune, frees the storage only they used.
     */
    public function forget(bool $prune): void
    {
        $keep = $this->settings->retention();
        $this->orFail($this->restic(['forget', '--host', $this->settings->host, '--group-by', 'host,tags', '--keep-daily', (string) $keep['daily'], '--keep-weekly', (string) $keep['weekly'], '--keep-monthly', (string) $keep['monthly'], ...($prune ? ['--prune'] : [])], 7200), 'Forgetting old backups');
    }

    /**
     * Checks the repository's structure, and reads back a part of its data ($percent) to check it too.
     */
    public function check(int $percent): ProcessResult
    {
        return $this->restic(['check', '--read-data-subset', max(1, min(100, $percent)).'%'], 7200);
    }

    /**
     * Restores files and folders of a snapshot into a folder (made here), at their paths under it.
     *
     * @param list<string> $paths
     */
    public function restorePaths(string $snapshot, array $paths, string $into): void
    {
        if (1 !== preg_match(self::SNAPSHOT, $snapshot) || [] === $paths) {
            throw new \InvalidArgumentException('Not a snapshot, or not a path in it.');
        }

        $includes = [];

        foreach ($paths as $path) {
            if (!str_starts_with($path, '/') || str_contains($path, '..')) {
                throw new \InvalidArgumentException('Not a snapshot, or not a path in it.');
            }
            $includes[] = '--include';
            $includes[] = $path;
        }

        $this->orFail($this->restic(['restore', $snapshot, '--target', $into, ...$includes], 7200), 'Getting the backup from off the server');
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function tags(array $tags): array
    {
        $arguments = [];

        foreach ($tags as $tag) {
            if (1 !== preg_match('/^[a-z0-9:-]{1,64}\z/', $tag)) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a tag.', $tag));
            }
            $arguments[] = '--tag';
            $arguments[] = $tag;
        }

        return $arguments;
    }

    /**
     * @param list<string> $arguments
     */
    private function restic(array $arguments, float $timeout): ProcessResult
    {
        try {
            return $this->run($arguments, $timeout);
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            throw new BackupFailed(\sprintf('restic got no answer from the bucket in %d seconds: check its address and region.', (int) $timeout));
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments, float $timeout): ProcessResult
    {
        return $this->runner->run(['restic', '--quiet', ...$arguments], [
            'RESTIC_REPOSITORY' => $this->settings->repository,
            'RESTIC_PASSWORD' => $this->settings->password,
            'AWS_ACCESS_KEY_ID' => $this->settings->accessKey,
            'AWS_SECRET_ACCESS_KEY' => $this->settings->secretKey,
            // Without it, restic asks the bucket for its location, which not every provider answers.
            'AWS_DEFAULT_REGION' => $this->settings->region,
            'RESTIC_CACHE_DIR' => $this->cache,
            // Only these: nothing inherited names another repository, password or key.
            'RESTIC_PASSWORD_FILE' => false,
            'RESTIC_PASSWORD_COMMAND' => false,
            'RESTIC_REPOSITORY_FILE' => false,
            'AWS_PROFILE' => false,
            'AWS_SESSION_TOKEN' => false,
        ], timeout: $timeout);
    }

    private function orFail(ProcessResult $result, string $what): ProcessResult
    {
        if (!$result->successful()) {
            throw new BackupFailed(\sprintf('%s failed: %s', $what, $this->reason($result)));
        }

        return $result;
    }

    private function reason(ProcessResult $result): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $result->errorOutput)), static fn (string $line): bool => '' !== $line));

        return '' === ($lines[0] ?? '') ? 'exit code '.$result->exitCode : implode(' ', \array_slice($lines, -3));
    }
}
