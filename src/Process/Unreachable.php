<?php

declare(strict_types=1);

namespace Quire\Server\Process;

/**
 * Whether a program that checks a signature (cosign) failed because nothing answered (a registry,
 * Sigstore, GitHub: an outage) rather than because the signature doesn't check out. Both stop what
 * needed it; only the second is an alarm.
 */
final class Unreachable
{
    private const string SAID = '/dial tcp|no such host|connection refused|i\/o timeout|TLS handshake timeout|deadline exceeded|network is unreachable|temporary failure in name resolution|Service Unavailable|Bad Gateway|Gateway Timeout|Too Many Requests/i';

    public static function said(string $output): bool
    {
        return 1 === preg_match(self::SAID, $output);
    }

    /**
     * The last thing it said, for a message (cosign ends with its reason).
     */
    public static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $output)), static fn (string $line): bool => '' !== $line));

        return mb_substr($lines[\count($lines) - 1] ?? 'no output', 0, 300);
    }
}
