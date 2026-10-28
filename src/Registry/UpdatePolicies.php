<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Doctrine\DBAL\Connection;
use Quire\Server\Site\SiteName;

/**
 * Which updates each site gets by itself, every night: those that fix a known vulnerability
 * (the default), all of them (within what its composer.json allows), or none.
 */
final readonly class UpdatePolicies
{
    public const string SECURITY = 'security';
    public const string ALL = 'all';
    public const string OFF = 'off';
    public const array POLICIES = [self::SECURITY, self::ALL, self::OFF];

    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    public function of(SiteName $site): string
    {
        $policy = $this->connection->fetchOne(\sprintf('SELECT updates FROM %s.site WHERE name = ?', $this->schema()), [$site->value]);

        return \is_string($policy) ? $policy : throw new \DomainException(\sprintf('There\'s no site named "%s".', $site));
    }

    public function set(SiteName $site, string $policy): void
    {
        if (!\in_array($policy, self::POLICIES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a policy: use %s.', SiteName::printable($policy), implode(', ', self::POLICIES)));
        }

        if (0 === $this->connection->executeStatement(\sprintf("UPDATE %s.site SET updates = ? WHERE name = ? AND status <> 'removed'", $this->schema()), [$policy, $site->value])) {
            throw new \DomainException(\sprintf('There\'s no site named "%s".', $site));
        }
    }

    /**
     * Running sites that get automatic updates, not looked at since the last night's window opened
     * (once that window is open: the hour, in UTC, from which updates are looked for each day). Git
     * mode sites are among them: their updates are made in their repository, but they're built
     * again on a newer runtime.
     *
     * @return list<SiteName>
     */
    public function due(int $hour): array
    {
        $names = $this->connection->fetchFirstColumn(\sprintf(
            <<<'SQL'
                SELECT s.name FROM %1$s.site s
                WHERE s.status = 'running' AND s.updates <> 'off'
                  AND now() >= date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + make_interval(hours => ?)
                  AND (s.updates_checked_at IS NULL OR s.updates_checked_at < date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + make_interval(hours => ?))
                ORDER BY s.name
                SQL,
            $this->schema(),
        ), [$hour, $hour]);

        return array_map(static fn (mixed $name): SiteName => new SiteName(\is_string($name) ? $name : ''), $names);
    }

    /**
     * Running sites that get automatic updates (Git mode ones too), whenever they were looked at.
     *
     * @return list<SiteName>
     */
    public function automatic(): array
    {
        $names = $this->connection->fetchFirstColumn(\sprintf("SELECT name FROM %s.site WHERE status = 'running' AND updates <> 'off' ORDER BY name", $this->schema()));

        return array_map(static fn (mixed $name): SiteName => new SiteName(\is_string($name) ? $name : ''), $names);
    }

    /**
     * Running sites whose updates the server makes (not Git mode ones), whatever their policy.
     *
     * @return list<SiteName>
     */
    public function updatable(): array
    {
        $names = $this->connection->fetchFirstColumn(\sprintf(
            "SELECT s.name FROM %1\$s.site s WHERE s.status = 'running' AND NOT EXISTS (SELECT 1 FROM %1\$s.site_git g WHERE g.site = s.name) ORDER BY s.name",
            $this->schema(),
        ));

        return array_map(static fn (mixed $name): SiteName => new SiteName(\is_string($name) ? $name : ''), $names);
    }

    public function checked(SiteName $site): void
    {
        $this->connection->executeStatement(\sprintf('UPDATE %s.site SET updates_checked_at = now() WHERE name = ?', $this->schema()), [$site->value]);
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }
}
