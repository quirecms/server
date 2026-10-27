<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Quire\Server\Git\Repository;
use Quire\Server\Site\SiteName;

/**
 * Which sites follow a Git repository (Git mode), what they run of it, and whether the agent watches
 * their branch for new commits.
 */
final readonly class SiteGit
{
    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    public function follow(SiteName $site, Repository $repository, bool $poll): void
    {
        $this->connection->executeStatement(
            \sprintf('INSERT INTO %s.site_git (site, url, branch, poll) VALUES (?, ?, ?, ?) ON CONFLICT (site) DO UPDATE SET url = EXCLUDED.url, branch = EXCLUDED.branch, poll = EXCLUDED.poll, deployed_commit = NULL, failed_commit = NULL, polled_at = NULL', $this->schema()),
            [$site->value, $repository->url, $repository->branch, $poll],
            [3 => Types::BOOLEAN],
        );
    }

    /**
     * The site follows no repository any more (it's removed, or its adding was undone): its name can be
     * used again for a managed site.
     */
    public function forget(SiteName $site): void
    {
        $this->connection->executeStatement(\sprintf('DELETE FROM %s.site_git WHERE site = ?', $this->schema()), [$site->value]);
    }

    /**
     * @return array{repository: Repository, deployed: ?string, failed: ?string, poll: bool}|null Null for a site managed by the server
     */
    public function of(SiteName $site): ?array
    {
        $row = $this->connection->fetchAssociative(\sprintf('SELECT * FROM %s.site_git WHERE site = ?', $this->schema()), [$site->value]);

        return false === $row ? null : $this->row($row);
    }

    public function deployed(SiteName $site, string $commit): void
    {
        $this->connection->executeStatement(\sprintf('UPDATE %s.site_git SET deployed_commit = ?, failed_commit = NULL WHERE site = ?', $this->schema()), [$commit, $site->value]);
    }

    /**
     * A commit that failed to deploy: the agent doesn't try it again by itself.
     */
    public function failed(SiteName $site, string $commit): void
    {
        $this->connection->executeStatement(\sprintf('UPDATE %s.site_git SET failed_commit = ? WHERE site = ?', $this->schema()), [$commit, $site->value]);
    }

    public function setPolling(SiteName $site, bool $poll): void
    {
        $this->connection->executeStatement(\sprintf('UPDATE %s.site_git SET poll = ? WHERE site = ?', $this->schema()), [$poll, $site->value], [Types::BOOLEAN]);
    }

    /**
     * Running sites whose branch the agent watches, not asked for this long.
     *
     * @return list<SiteName>
     */
    public function dueForPolling(int $seconds): array
    {
        $names = $this->connection->fetchFirstColumn(\sprintf(
            "SELECT g.site FROM %1\$s.site_git g JOIN %1\$s.site s ON s.name = g.site WHERE g.poll AND s.status = 'running' AND (g.polled_at IS NULL OR g.polled_at < now() - make_interval(secs => ?)) ORDER BY g.site",
            $this->schema(),
        ), [$seconds]);

        return array_map(static fn (mixed $name): SiteName => new SiteName(\is_string($name) ? $name : ''), $names);
    }

    public function polled(SiteName $site): void
    {
        $this->connection->executeStatement(\sprintf('UPDATE %s.site_git SET polled_at = now() WHERE site = ?', $this->schema()), [$site->value]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{repository: Repository, deployed: ?string, failed: ?string, poll: bool}
     */
    private function row(array $row): array
    {
        return [
            'repository' => new Repository(\is_string($row['url']) ? $row['url'] : '', \is_string($row['branch']) ? $row['branch'] : ''),
            'deployed' => \is_string($row['deployed_commit']) ? $row['deployed_commit'] : null,
            'failed' => \is_string($row['failed_commit']) ? $row['failed_commit'] : null,
            'poll' => (bool) $row['poll'],
        ];
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }
}
