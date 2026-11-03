<?php

declare(strict_types=1);

// Run inside a site's container (D-34): what it reaches of other sites and of the server.
//
//   php try-links.php tcp <host:port>...                  one line each: "<host:port> reached" or "not reached"
//   php try-links.php login <database> <user> <password>  "in", "refused" (pg_hba.conf), or what else failed
if ('tcp' === ($argv[1] ?? '')) {
    foreach (array_slice($argv, 2) as $target) {
        [$host, $port] = explode(':', $target, 2);
        $socket = @fsockopen($host, (int) $port, $code, $message, 3);
        echo $target, false === $socket ? ' not reached' : ' reached', "\n";
    }

    exit(0);
}

if ('login' === ($argv[1] ?? '')) {
    try {
        new PDO(sprintf('pgsql:host=quire-database;dbname=%s', $argv[2] ?? ''), $argv[3] ?? '', $argv[4] ?? '');
        echo "in\n";
    } catch (PDOException $e) {
        echo str_contains($e->getMessage(), 'pg_hba.conf rejects') ? 'refused' : 'other: '.$e->getMessage(), "\n";
    }

    exit(0);
}

fwrite(\STDERR, "Usage: php try-links.php tcp <host:port>... | login <database> <user> <password>\n");
exit(2);
