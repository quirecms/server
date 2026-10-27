<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

/**
 * The registry's tables, in their own PostgreSQL schema ("server") of the server's database: the
 * sites, their domains, and every operation run on them with its log. The server's migrations run
 * these statements; so do the tests.
 */
final class Schema
{
    public const string NAME = 'server';

    /**
     * @return list<string>
     */
    public static function version1(string $schema = self::NAME): array
    {
        $s = '"'.str_replace('"', '""', $schema).'"';

        return [
            "CREATE SCHEMA IF NOT EXISTS {$s}",
            <<<SQL
                CREATE TABLE {$s}.site (
                    name text PRIMARY KEY CHECK (name ~ '^[a-z][a-z0-9-]{1,29}$'),
                    status text NOT NULL CHECK (status IN ('creating', 'running', 'removing', 'failed', 'removed')),
                    database_mode text NOT NULL CHECK (database_mode IN ('shared', 'own')),
                    project text NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT now(),
                    updated_at timestamptz NOT NULL DEFAULT now(),
                    removed_at timestamptz
                )
                SQL,
            <<<SQL
                CREATE TABLE {$s}.domain (
                    domain text PRIMARY KEY CHECK (domain ~ '^[a-z0-9.-]{3,253}$'),
                    site text NOT NULL REFERENCES {$s}.site (name) ON DELETE CASCADE,
                    is_primary boolean NOT NULL DEFAULT false,
                    created_at timestamptz NOT NULL DEFAULT now()
                )
                SQL,
            "CREATE UNIQUE INDEX domain_one_primary ON {$s}.domain (site) WHERE is_primary",
            <<<SQL
                CREATE TABLE {$s}.operation (
                    id uuid PRIMARY KEY,
                    action text NOT NULL,
                    site text,
                    parameters jsonb NOT NULL DEFAULT '{}',
                    channel text NOT NULL CHECK (channel IN ('cli', 'console', 'agent')),
                    requested_by text NOT NULL,
                    status text NOT NULL CHECK (status IN ('running', 'succeeded', 'failed')),
                    error text,
                    started_at timestamptz NOT NULL DEFAULT now(),
                    finished_at timestamptz
                )
                SQL,
            "CREATE INDEX operation_site ON {$s}.operation (site, started_at DESC)",
            <<<SQL
                CREATE TABLE {$s}.operation_log (
                    operation_id uuid NOT NULL REFERENCES {$s}.operation (id) ON DELETE CASCADE,
                    seq integer NOT NULL,
                    at timestamptz NOT NULL DEFAULT now(),
                    line text NOT NULL,
                    PRIMARY KEY (operation_id, seq)
                )
                SQL,
        ];
    }
}
