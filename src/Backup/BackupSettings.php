<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

/**
 * Where backups go off the server, and how they're kept (server.env, QUIRE_SERVER_BACKUP_*): a restic
 * repository on S3-compatible storage, its keys, and the password its data is encrypted with.
 * Without a repository, there are none.
 */
final readonly class BackupSettings
{
    /** An S3-compatible bucket (and optional folder), as restic names it */
    public const string REPOSITORY = '#^s3:https?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?/[a-z0-9][a-z0-9.-]{1,61}[a-z0-9](?:/[A-Za-z0-9._/-]+)?\z#';

    public function __construct(
        public string $repository,
        public string $accessKey,
        public string $secretKey,
        public string $password,
        public string $region = 'us-east-1',
        public string $host = 'quire-server',
        public string $hour = '2',
        public string $keep = '7 daily, 4 weekly, 6 monthly',
    ) {
    }

    public function configured(): bool
    {
        return '' !== $this->repository;
    }

    /**
     * @throws \InvalidArgumentException When a setting isn't what restic needs
     */
    public function validate(): void
    {
        if (1 !== preg_match(self::REPOSITORY, $this->repository) || \in_array('..', explode('/', $this->repository), true)) {
            throw new \InvalidArgumentException('QUIRE_SERVER_BACKUP_REPOSITORY is an S3-compatible bucket, e.g. "s3:https://fra1.digitaloceanspaces.com/my-backups/quire".');
        }

        foreach (['QUIRE_SERVER_BACKUP_ACCESS_KEY' => $this->accessKey, 'QUIRE_SERVER_BACKUP_SECRET_KEY' => $this->secretKey] as $name => $value) {
            if (1 !== preg_match('/^[\x21-\x7e]{1,256}\z/', $value)) {
                throw new \InvalidArgumentException(\sprintf('%s is missing, or isn\'t a key.', $name));
            }
        }

        if (\strlen($this->password) < 20 || 1 !== preg_match('/^[\x21-\x7e]+\z/', $this->password)) {
            throw new \InvalidArgumentException('QUIRE_SERVER_BACKUP_PASSWORD is missing or too short (at least 20 characters, no spaces).');
        }

        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{1,30}\z/', $this->region)) {
            throw new \InvalidArgumentException('QUIRE_SERVER_BACKUP_REGION is the bucket\'s region, e.g. "fra1", "eu-central-003" or "us-east-1".');
        }

        if (1 !== preg_match('/^[a-z0-9][a-z0-9.-]{0,62}\z/', $this->host)) {
            throw new \InvalidArgumentException('QUIRE_SERVER_BACKUP_HOST is a short name for this server in the backups, e.g. "quire-server".');
        }

        $this->retention();
    }

    /**
     * @return array{daily: int, weekly: int, monthly: int}
     *
     * @throws \InvalidArgumentException When QUIRE_SERVER_BACKUP_KEEP isn't "<n> daily, <n> weekly, <n> monthly"
     */
    public function retention(): array
    {
        if (1 !== preg_match('/^([0-9]{1,3}) daily, ([0-9]{1,3}) weekly, ([0-9]{1,3}) monthly\z/', $this->keep, $match) || '0' === $match[1]) {
            throw new \InvalidArgumentException('QUIRE_SERVER_BACKUP_KEEP is "<n> daily, <n> weekly, <n> monthly", at least one daily, e.g. "7 daily, 4 weekly, 6 monthly".');
        }

        return ['daily' => (int) $match[1], 'weekly' => (int) $match[2], 'monthly' => (int) $match[3]];
    }

    public function plainHttp(): bool
    {
        return str_starts_with($this->repository, 's3:http://');
    }

    /**
     * Values never to be written to a log.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->secretKey, $this->password], static fn (string $secret): bool => '' !== $secret));
    }
}
