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

    /**
     * Updates of sites, to undo the last one: the image and files before it, its backup.
     *
     * @return list<string>
     */
    public static function version2(string $schema = self::NAME): array
    {
        $s = '"'.str_replace('"', '""', $schema).'"';

        return [
            <<<SQL
                CREATE TABLE {$s}.site_update (
                    id uuid PRIMARY KEY,
                    site text NOT NULL REFERENCES {$s}.site (name) ON DELETE CASCADE,
                    operation_id uuid NOT NULL REFERENCES {$s}.operation (id),
                    quire_from text,
                    quire_to text,
                    packages jsonb NOT NULL DEFAULT '[]',
                    image_before text NOT NULL CHECK (image_before ~ '^sha256:[0-9a-f]{64}$'),
                    image_after text NOT NULL CHECK (image_after ~ '^sha256:[0-9a-f]{64}$'),
                    saved_files text NOT NULL,
                    skeleton_paths jsonb NOT NULL DEFAULT '[]',
                    backup text NOT NULL,
                    migrations_ran boolean,
                    status text NOT NULL CHECK (status IN ('done', 'undone', 'superseded')),
                    created_at timestamptz NOT NULL DEFAULT now(),
                    undone_at timestamptz
                )
                SQL,
            "CREATE INDEX site_update_site ON {$s}.site_update (site, created_at DESC)",
        ];
    }

    /**
     * Sites that follow a Git repository (Git mode), and deploys among the site's updates.
     *
     * @return list<string>
     */
    public static function version3(string $schema = self::NAME): array
    {
        $s = '"'.str_replace('"', '""', $schema).'"';

        return [
            <<<SQL
                CREATE TABLE {$s}.site_git (
                    site text PRIMARY KEY REFERENCES {$s}.site (name) ON DELETE CASCADE,
                    url text NOT NULL,
                    branch text NOT NULL,
                    deployed_commit text CHECK (deployed_commit ~ '^([0-9a-f]{40}|[0-9a-f]{64})$'),
                    failed_commit text CHECK (failed_commit ~ '^([0-9a-f]{40}|[0-9a-f]{64})$'),
                    poll boolean NOT NULL DEFAULT false,
                    polled_at timestamptz
                )
                SQL,
            "ALTER TABLE {$s}.site_update ADD COLUMN kind text NOT NULL DEFAULT 'update' CHECK (kind IN ('update', 'deploy'))",
            "ALTER TABLE {$s}.site_update ADD COLUMN commit_before text",
            "ALTER TABLE {$s}.site_update ADD COLUMN commit_after text",
        ];
    }

    /**
     * Each site's automatic updates: which it gets, and when they were last looked for.
     *
     * @return list<string>
     */
    public static function version4(string $schema = self::NAME): array
    {
        $s = '"'.str_replace('"', '""', $schema).'"';

        return [
            "ALTER TABLE {$s}.site ADD COLUMN updates text NOT NULL DEFAULT 'security' CHECK (updates IN ('security', 'all', 'off'))",
            "ALTER TABLE {$s}.site ADD COLUMN updates_checked_at timestamptz",
        ];
    }

    /**
     * The runtime images each site's image was last built on (by digest): a newer one, with its
     * security fixes, is built on at night.
     *
     * @return list<string>
     */
    public static function version5(string $schema = self::NAME): array
    {
        $s = '"'.str_replace('"', '""', $schema).'"';

        return [
            <<<SQL
                CREATE TABLE {$s}.site_runtime (
                    project text PRIMARY KEY,
                    images jsonb NOT NULL,
                    built_at timestamptz NOT NULL DEFAULT now()
                )
                SQL,
        ];
    }

    /**
     * The newest build of each of Quire's runtime images this server built on: one older than it (a
     * tag moved back) isn't built on.
     *
     * @return list<string>
     */
    public static function version6(string $schema = self::NAME): array
    {
        $s = '"'.str_replace('"', '""', $schema).'"';

        return [
            <<<SQL
                CREATE TABLE {$s}.runtime_floor (
                    image text PRIMARY KEY,
                    digest text NOT NULL,
                    created timestamptz NOT NULL,
                    seen_at timestamptz NOT NULL DEFAULT now()
                )
                SQL,
        ];
    }
}
