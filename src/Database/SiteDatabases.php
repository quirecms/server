<?php

declare(strict_types=1);

namespace Quire\Server\Database;

use Doctrine\DBAL\Connection;
use Quire\Server\Site\SiteName;

/**
 * Sites' databases on the server's PostgreSQL, through its administrative role (only the agent has
 * it). Each site gets a database and a role of the same name ("site_jane") that can log in, owns
 * that database and nothing else, and can't create databases or roles. Nobody else may connect to it.
 *
 * Passwords are sent as SCRAM-SHA-256 verifiers computed here, never as plain text, so they can't
 * show up in PostgreSQL's logs or statistics.
 */
final readonly class SiteDatabases
{
    public function __construct(
        private Connection $admin,
    ) {
    }

    /**
     * Creates the site's role and database.
     *
     * @return string The role's password, for the site's prod.env
     */
    public function create(SiteName $site): string
    {
        $identifier = $site->databaseIdentifier();
        $quoted = $this->admin->quoteSingleIdentifier($identifier);
        $password = bin2hex(random_bytes(24));

        if ($this->exists($site)) {
            throw new \DomainException(\sprintf('The database or role "%s" exists already.', $identifier));
        }

        // CREATE DATABASE can't run in a transaction.
        $this->admin->executeStatement(\sprintf(
            'CREATE ROLE %s LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 64 PASSWORD %s',
            $quoted,
            $this->admin->quote(self::scramVerifier($password)),
        ));

        try {
            $this->admin->executeStatement(\sprintf("CREATE DATABASE %s OWNER %1\$s TEMPLATE template0 ENCODING 'UTF8'", $quoted));
            $this->admin->executeStatement(\sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $quoted));
            $this->admin->executeStatement(\sprintf('GRANT CONNECT, TEMPORARY ON DATABASE %1$s TO %1$s', $quoted));
        } catch (\Throwable $e) {
            $this->drop($site);

            throw $e;
        }

        return $password;
    }

    /**
     * Removes the site's database (ending its connections) and role; nothing when they don't exist.
     */
    public function drop(SiteName $site): void
    {
        $identifier = $site->databaseIdentifier();
        $quoted = $this->admin->quoteSingleIdentifier($identifier);

        $this->admin->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $quoted));
        $this->admin->executeStatement(\sprintf('DROP ROLE IF EXISTS %s', $quoted));
    }

    public function exists(SiteName $site): bool
    {
        $identifier = $site->databaseIdentifier();

        return false !== $this->admin->fetchOne('SELECT 1 FROM pg_database WHERE datname = ? UNION ALL SELECT 1 FROM pg_roles WHERE rolname = ?', [$identifier, $identifier]);
    }

    /**
     * What every server does once to its PostgreSQL: nobody connects to a database unless allowed, and
     * the public schemas take no new objects from others.
     */
    public function harden(string $serverDatabase): void
    {
        foreach (['postgres', 'template1', $serverDatabase] as $database) {
            $this->admin->executeStatement(\sprintf('REVOKE CONNECT, TEMPORARY ON DATABASE %s FROM PUBLIC', $this->admin->quoteSingleIdentifier($database)));
        }

        $this->admin->executeStatement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
    }

    /**
     * PostgreSQL's SCRAM-SHA-256 verifier of a password (RFC 7677, as pg_authid stores it).
     *
     * @param int<1, max> $iterations
     */
    public static function scramVerifier(string $password, ?string $salt = null, int $iterations = 4096): string
    {
        $salt ??= random_bytes(16);
        $salted = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);
        $storedKey = hash('sha256', $clientKey, true);
        $serverKey = hash_hmac('sha256', 'Server Key', $salted, true);

        return \sprintf('SCRAM-SHA-256$%d:%s$%s:%s', $iterations, base64_encode($salt), base64_encode($storedKey), base64_encode($serverKey));
    }
}
