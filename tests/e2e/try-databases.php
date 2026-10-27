<?php

declare(strict_types=1);

// Run inside a site's container: with the site's own credentials, which of the server's databases
// open? Each should say "denied" (PostgreSQL's "permission denied"), not fail for another reason.
$url = parse_url((string) getenv('DATABASE_URL'));

foreach (['postgres', 'quire_server', 'template1'] as $database) {
    try {
        new PDO(sprintf('pgsql:host=%s;dbname=%s', $url['host'] ?? '', $database), $url['user'] ?? '', urldecode($url['pass'] ?? ''));
        echo $database, ": OPEN\n";
    } catch (PDOException $e) {
        echo $database, ': ', str_contains($e->getMessage(), 'permission denied') ? 'denied' : 'other: '.$e->getMessage(), "\n";
    }
}
