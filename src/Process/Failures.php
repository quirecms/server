<?php

declare(strict_types=1);

namespace Quire\Server\Process;

/**
 * What a tool's failure means, in a plain sentence, and what to do (M-20): from its whole output, for
 * the failures an owner meets (a full disk, a host that can't be reached, a bucket that refuses its
 * keys, the backups' password, a deploy key refused, Composer that can't resolve, a build step that
 * failed). The tool's own words stay in the operation's log; the owner reads these.
 */
final class Failures
{
    /**
     * @return array{why: string, next: string}|null Null: not one it knows
     */
    public static function explain(string $output): ?array
    {
        // Phrases, whatever the lines they're broken over; BuildKit's and Composer's lines as they are
        $lines = $output;
        $output = (string) preg_replace('/\s+/', ' ', $output);

        if (1 === preg_match('/no space left on device/i', $output)) {
            return ['why' => 'the disk is full (no space left on device)', 'next' => 'Make room on it ("quire-server status" says how much is free; old backups are in /srv/quire/backups, "docker system df" shows what Docker\'s images take), then try again.'];
        }

        if (1 === preg_match('/wrong password or no key found/i', $output)) {
            return ['why' => 'the backups\' password (QUIRE_SERVER_BACKUP_PASSWORD in server.env) doesn\'t open the repository', 'next' => 'Put back the password the repository was made with (the one shown when backups were set up), then "quire-server up".'];
        }

        if (1 === preg_match('/\b(Access ?Denied|InvalidAccessKeyId|SignatureDoesNotMatch|The AWS Access Key Id you provided does not exist)\b/i', $output)) {
            return ['why' => 'the bucket refused the server\'s keys (access denied)', 'next' => 'Check QUIRE_SERVER_BACKUP_ACCESS_KEY and QUIRE_SERVER_BACKUP_SECRET_KEY in server.env, and that the key may read and write the bucket ("quire-server backups:setup" sets them again).'];
        }

        if (1 === preg_match('/Permission denied \(publickey/i', $output)) {
            return ['why' => 'the repository refused the site\'s deploy key', 'next' => 'Add it to the repository again as a read-only deploy key ("quire-server site:key <site>" shows it), then try again.'];
        }

        if (1 === preg_match('/Your requirements could not be resolved|Problem 1\s/', $output)) {
            $reason = 1 === preg_match('/Problem 1\s+(?:#\d+ [\d.]+\s+)?- (.+)/', $lines, $match) ? ': '.self::clean($match[1]) : '';

            return ['why' => 'Composer couldn\'t work out the versions to install'.$reason, 'next' => 'Fix the site\'s composer.json (or the constraint asked for), then try again.'];
        }

        if (self::transient($output)) {
            $host = 1 === preg_match('/"?https?:\/\/([A-Za-z0-9.-]+\.[A-Za-z]{2,})[\/"]/', $output, $match) ? $match[1] : null;
            $status = 1 === preg_match('/\bHTTP\/[\d.]+ (\d{3})\b/', $output, $match) ? ' HTTP '.$match[1] : null;
            $package = 1 === preg_match('/Failed to download (\S+) from dist/', $output, $match) ? ' while '.$match[1].' was downloaded' : '';

            return ['why' => \sprintf('%s%s%s: a problem on its side or on the way, usually brief', $host ?? 'a host it needs', null === $status ? ' didn\'t answer in time' : ' answered'.$status, $package), 'next' => 'Try again in a few minutes. If it goes on, check the host\'s status page and the server\'s network.'];
        }

        if (1 === preg_match('/Could not resolve host|Failed to connect to|could not translate host name|Temporary failure in name resolution|Name (?:does not resolve|or service not known)|Connection timed out|Connection refused|Network is unreachable|i\/o timeout|TLS handshake timeout|curl error (?:6|7|28)\b/i', $output)) {
            $host = null;

            foreach (['/Could not resolve host:? ([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', '/Failed to connect to ([A-Za-z0-9.-]+)/', '/could not translate host name "([^"]+)"/', '/Connection to ([A-Za-z0-9.:-]+) port \d+/', '/downloading https?:\/\/([A-Za-z0-9.-]+)/', '/dial tcp:? (?:lookup )?([A-Za-z0-9.-]+\.[A-Za-z]{2,})/'] as $pattern) {
                if (1 === preg_match($pattern, $output, $match)) {
                    $host = $match[1];

                    break;
                }
            }

            return ['why' => null === $host ? 'a host it needs couldn\'t be reached from this server' : $host.' couldn\'t be reached from this server', 'next' => 'Check the server\'s network and DNS (and that the host is up), then try again.'];
        }

        if (1 === preg_match('/process "(?:\/\S+(?: (?!-c )\S+)* -c )?(.{1,2000}?)"\s+did not complete successfully: exit code: (\d+)/s', $output, $match)) {
            // What the failing step said last ("#20 0.512 asset build failed: out of memory"), else its command
            $said = null;

            if (1 === preg_match('/#(\d+) ERROR: process/', $lines, $step) && preg_match_all('/^#'.$step[1].' [\d.]+ (.+)$/m', $lines, $stepLines) > 0) {
                // The last line that says something: not a command's usage Composer and Symfony print after
                // their error ("install [--prefer-source] [--prefer-dist] …")
                foreach (array_reverse($stepLines[1]) as $line) {
                    if ('' !== trim($line) && 1 !== preg_match('/^\s*[\w:-]* ?\[--[\w-]+/', $line)) {
                        $said = $line;

                        break;
                    }
                }
            }

            return ['why' => \sprintf('a step of its build failed (exit code %s): %s', $match[2], self::clean($said ?? strtok(str_replace('\\n', "\n", $match[1]), "\n"), 160)), 'next' => 'The operation\'s log ("quire-server operations") has the step\'s whole output; fix what it says in the site\'s code, then try again.'];
        }

        return null;
    }

    /**
     * Whether a failure is one that passes by itself: a host's server error or rate limit (HTTP 5xx,
     * 429), a connection cut or timed out on the way. Not a name that doesn't resolve or a connection
     * refused: those are usually a setting, and trying again only makes the owner wait.
     */
    public static function transient(string $output): bool
    {
        $output = (string) preg_replace('/\s+/', ' ', $output);

        return 1 === preg_match('/(?:could not be downloaded|Failed to download|failed to (?:fetch|resolve source metadata|copy)|unexpected status|returned error|status code)[^.]{0,200}?\bHTTP\/[\d.]+ (?:5\d\d|429)\b|\bHTTP\/[\d.]+ (?:5\d\d|429)\b[^.]{0,40}(?:Gateway|Service Unavailable|Bad Gateway|Too Many Requests|Internal Server Error)|\b(?:502 Bad Gateway|503 Service Unavailable|504 Gateway Time-?out|429 Too Many Requests)\b|Connection reset by peer|TLS handshake timeout|i\/o timeout|Operation timed out|curl error 28\b|unexpected EOF/i', $output);
    }

    /**
     * One line of a tool's output, as an owner reads it: without terminal codes, extra spaces or
     * BuildKit's prefixes, and not too long.
     */
    private static function clean(string $line, int $length = 300): string
    {
        $line = trim((string) preg_replace(['/\e\[[0-9;]*m/', '/\\\\[nt]/', '/\s+/'], ['', ' ', ' '], $line));

        return mb_strlen($line) > $length ? mb_substr($line, 0, $length - 1).'…' : $line;
    }
}
