<?php

declare(strict_types=1);

// Run inside a site's container: with the site's own credentials, which of the server's databases
// open? Each should be refused, not fail for another reason: "refused" (pg_hba.conf: its role logs in
// to its own database only, D-34) or "denied" (PostgreSQL's "permission denied", the databases' rights).
$url = parse_url((string) getenv('DATABASE_URL'));

foreach (['postgres', 'quire_server', 'template1'] as $database) {
    try {
        new PDO(sprintf('pgsql:host=%s;dbname=%s', $url['host'] ?? '', $database), $url['user'] ?? '', urldecode($url['pass'] ?? ''));
        echo $database, ": OPEN\n";
    } catch (PDOException $e) {
        echo $database, ': ', match (true) {
            str_contains($e->getMessage(), 'pg_hba.conf rejects') => 'refused',
            str_contains($e->getMessage(), 'permission denied') => 'denied',
            default => 'other: '.$e->getMessage(),
        }, "\n";
    }
}
