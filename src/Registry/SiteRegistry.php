<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;

/**
 * The server's sites and their domains. A domain belongs to one site at most; a removed site keeps its
 * row (and history) but loses its domains, and its name can be used again.
 */
final readonly class SiteRegistry
{
    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    /**
     * Records a new site, its domains (the first is the primary one). Fails when the name is taken by a
     * site that isn't removed, or a domain belongs to another site.
     *
     * @param list<Domain> $domains
     */
    public function add(SiteName $name, array $domains, string $databaseMode, string $project): Site
    {
        if ([] === $domains) {
            throw new \InvalidArgumentException('A site needs at least one domain.');
        }

        $this->connection->transactional(function () use ($name, $domains, $databaseMode, $project): void {
            $existing = $this->connection->fetchOne(\sprintf('SELECT status FROM %s.site WHERE name = ? FOR UPDATE', $this->schema()), [$name->value]);

            if (false !== $existing && Site::REMOVED !== $existing) {
                throw new \DomainException(\sprintf('There\'s already a site named "%s".', $name));
            }

            if (false === $existing) {
                $this->connection->insert($this->table('site'), ['name' => $name->value, 'status' => Site::CREATING, 'database_mode' => $databaseMode, 'project' => $project]);
            } else {
                // A removed site's name, used again: a new site, with the old one's history kept.
                $this->connection->executeStatement(
                    \sprintf('UPDATE %s.site SET status = ?, database_mode = ?, project = ?, removed_at = NULL, created_at = now(), updated_at = now() WHERE name = ?', $this->schema()),
                    [Site::CREATING, $databaseMode, $project, $name->value],
                );
            }

            $values = array_map(static fn (Domain $domain): string => $domain->value, $domains);
            $owners = $this->connection->fetchAllKeyValue(\sprintf('SELECT domain, site FROM %s.domain WHERE domain IN (?) ORDER BY domain', $this->schema()), [$values], [ArrayParameterType::STRING]);

            foreach ($owners as $domain => $owner) {
                throw new \DomainException(\sprintf('The domain "%s" belongs to the site "%s".', $domain, \is_scalar($owner) ? (string) $owner : '?'));
            }

            foreach ($domains as $index => $domain) {
                try {
                    $this->connection->insert($this->table('domain'), ['domain' => $domain->value, 'site' => $name->value, 'is_primary' => 0 === $index], ['is_primary' => 'boolean']);
                } catch (UniqueConstraintViolationException $e) {
                    // Taken meanwhile by another site being added.
                    throw new \DomainException(\sprintf('The domain "%s" was just taken by another site.', $domain), $e->getCode(), previous: $e);
                }
            }
        });

        return $this->get($name) ?? throw new \LogicException('The site was just added.');
    }

    public function setStatus(SiteName $name, string $status): void
    {
        if (!\in_array($status, [Site::CREATING, Site::RUNNING, Site::REMOVING, Site::FAILED, Site::REMOVED], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown status "%s".', $status));
        }

        $this->connection->transactional(function () use ($name, $status): void {
            if (Site::REMOVED !== $status) {
                $this->connection->executeStatement(\sprintf('UPDATE %s.site SET status = ?, updated_at = now() WHERE name = ?', $this->schema()), [$status, $name->value]);

                return;
            }

            $this->connection->executeStatement(\sprintf('UPDATE %s.site SET status = ?, updated_at = now(), removed_at = now() WHERE name = ?', $this->schema()), [$status, $name->value]);
            // A removed site's domains are free for others.
            $this->connection->delete($this->table('domain'), ['site' => $name->value]);
        });
    }

    public function get(SiteName $name): ?Site
    {
        $row = $this->connection->fetchAssociative(\sprintf('SELECT * FROM %s.site WHERE name = ?', $this->schema()), [$name->value]);

        return false === $row ? null : $this->site($row);
    }

    /**
     * @return list<Site> By name; removed sites only when asked
     */
    public function all(bool $withRemoved = false): array
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf('SELECT * FROM %s.site %s ORDER BY name', $this->schema(), $withRemoved ? '' : "WHERE status <> 'removed'"));

        return array_map($this->site(...), $rows);
    }

    /**
     * The domains of the sites that run, with their site: what the front door may serve. (Not those of
     * a site being created: it's opened once it's installed.).
     *
     * @return array<string, string> Domain => site name
     */
    public function servedDomains(): array
    {
        $domains = [];

        foreach ($this->connection->fetchAllKeyValue(\sprintf("SELECT d.domain, d.site FROM %1\$s.domain d JOIN %1\$s.site s ON s.name = d.site WHERE s.status = 'running' ORDER BY d.domain", $this->schema())) as $domain => $site) {
            $domains[(string) $domain] = self::text($site);
        }

        return $domains;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function site(array $row): Site
    {
        $name = new SiteName(self::text($row['name']));
        $domains = $this->connection->fetchFirstColumn(\sprintf('SELECT domain FROM %s.domain WHERE site = ? ORDER BY is_primary DESC, domain', $this->schema()), [$name->value]);

        return new Site(
            $name,
            self::text($row['status']),
            self::text($row['database_mode']),
            self::text($row['project']),
            array_map(static fn (mixed $domain): Domain => new Domain(self::text($domain)), $domains),
            new \DateTimeImmutable(self::text($row['created_at'])),
        );
    }

    private function table(string $table): string
    {
        return $this->schema().'.'.$table;
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
