<?php

declare(strict_types=1);

namespace Quire\Server\ServerDatabase;

/**
 * What must be the same in both majors after a copy, as lines to compare: the roles (with their
 * passwords' hashes), memberships, databases (owner, encoding, rights), settings; and in each database
 * its schemas, tables with their exact row counts, indexes, views, sequences' values, functions,
 * constraints, triggers, policies and extensions. What an extension makes is left out: a newer major
 * may bring a newer version of it.
 *
 * Differences are told by kind and name only, never with a line's content (it may hold a hash).
 */
final class DatabaseCheck
{
    public const string CLUSTER = <<<'SQL'
        SELECT 'role ' || rolname || ' ' || concat_ws(',', rolsuper, rolinherit, rolcreaterole, rolcreatedb, rolcanlogin, rolreplication, rolbypassrls, rolconnlimit, rolvaliduntil) || ' ' || md5(coalesce(rolpassword, ''))
        FROM pg_authid WHERE rolname !~ '^pg_'
        UNION ALL
        SELECT 'member ' || pg_get_userbyid(roleid) || ' ' || pg_get_userbyid(member) || ' ' || admin_option
        FROM pg_auth_members WHERE pg_get_userbyid(member) !~ '^pg_'
        UNION ALL
        SELECT 'database ' || datname || ' ' || pg_get_userbyid(datdba) || ' ' || pg_encoding_to_char(encoding) || ' ' || coalesce(datacl::text, '')
        FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres'
        UNION ALL
        SELECT 'setting ' || coalesce((SELECT datname FROM pg_database WHERE oid = setdatabase), '*') || ' ' || coalesce(pg_get_userbyid(nullif(setrole, 0)), '*') || ' ' || setconfig::text
        FROM pg_db_role_setting
        ORDER BY 1
        SQL;

    public const string DATABASE = <<<'SQL'
        SELECT 'relation ' || n.nspname || '.' || c.relname || ' ' || c.relkind::text
            || CASE WHEN c.relkind = 'r' THEN ' rows ' || (xpath('/row/c/text()', query_to_xml(format('SELECT count(*) AS c FROM %I.%I', n.nspname, c.relname), false, true, '')))[1]::text ELSE '' END
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname !~ '^pg_toast'
            AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f', 'i', 'I')
            AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
        UNION ALL
        SELECT 'sequence ' || schemaname || '.' || sequencename || ' ' || coalesce(last_value::text, 'unset')
        FROM pg_sequences s
        WHERE NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = format('%I.%I', s.schemaname, s.sequencename)::regclass AND d.deptype = 'e')
        UNION ALL
        SELECT 'function ' || n.nspname || '.' || p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')'
        FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
            AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
        UNION ALL
        SELECT 'constraint ' || n.nspname || '.' || c.conname || ' ' || c.contype::text
        FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
        UNION ALL
        SELECT 'trigger ' || t.tgrelid::regclass::text || '.' || t.tgname FROM pg_trigger t WHERE NOT t.tgisinternal
        UNION ALL
        SELECT 'policy ' || p.polrelid::regclass::text || '.' || p.polname FROM pg_policy p
        UNION ALL
        SELECT 'extension ' || extname FROM pg_extension
        UNION ALL
        SELECT 'schema ' || nspname || ' ' || pg_get_userbyid(nspowner) || ' ' || coalesce(nspacl::text, '')
        FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname <> 'information_schema'
        ORDER BY 1
        SQL;

    /** The databases to copy: every one but the templates and PostgreSQL's own */
    public const string DATABASES = <<<'SQL'
        SELECT datname || '|' || datallowconn FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY 1
        SQL;

    /** What's in PostgreSQL's own database "postgres" (not copied: nothing of the server's is there) */
    public const string POSTGRES_DATABASE = <<<'SQL'
        SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname !~ '^pg_toast'
        SQL;

    /** The bytes every database takes */
    public const string SIZE = 'SELECT sum(pg_database_size(oid)) FROM pg_database';

    /**
     * @return list<string> What differs, by kind and name ("table quire.content (rows)", "role site_jane"), none when they're the same
     */
    public static function differences(string $old, string $new): array
    {
        $old = self::lines($old);
        $new = self::lines($new);
        $differences = [];

        foreach ([[$old, $new, 'missing'], [$new, $old, 'different or added']] as [$these, $those, $what]) {
            foreach (array_diff_key($these, $those) as $line => $true) {
                $differences[self::name($line)] = self::name($line).' ('.$what.')';
            }
        }

        ksort($differences);

        return array_values($differences);
    }

    /**
     * @return array{tables: int, rows: int}
     */
    public static function counts(string $inventory): array
    {
        $tables = 0;
        $rows = 0;

        foreach (array_keys(self::lines($inventory)) as $line) {
            if (1 === preg_match('/^relation \S+ r rows (\d+)\z/', $line, $match)) {
                ++$tables;
                $rows += (int) $match[1];
            }
        }

        return ['tables' => $tables, 'rows' => $rows];
    }

    /**
     * @return array<string, true>
     */
    private static function lines(string $output): array
    {
        $lines = [];

        foreach (explode("\n", $output) as $line) {
            if ('' !== trim($line)) {
                $lines[rtrim($line)] = true;
            }
        }

        return $lines;
    }

    /**
     * The kind and name of a line: its first two words.
     */
    private static function name(string $line): string
    {
        return implode(' ', \array_slice(explode(' ', $line), 0, 2));
    }
}
