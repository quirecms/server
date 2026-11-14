<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

/**
 * Asks the gate on its trusted socket (GET /_yorvogate/v1/scopes/<name>?listener=sites…). With
 * "sha256" or "absent" the gate scans its folders at once and waits up to 10 seconds; a wait that
 * ends without a match is a 408, which is a failure, never the state waited for.
 */
final readonly class GateScopeStatus implements ScopeStatus
{
    /** The scoped listener of gate/yorvogate.yaml that serves sites */
    public const string LISTENER = 'sites';

    /** The gate waits 10 seconds at most: this leaves it the time to answer */
    private const float TIMEOUT = 20.0;

    /** An answer is a few hundred bytes: more is something else */
    private const int MAX_ANSWER = 65536;

    public function __construct(
        /** The trusted socket, e.g. "unix:///run/quire-gate/trusted/trusted.sock" */
        private string $dockerHost,
    ) {
    }

    public function read(string $project, string $sha256): array
    {
        if (1 !== preg_match('/^[0-9a-f]{64}\z/', $sha256)) {
            throw new \InvalidArgumentException('A scope file\'s SHA-256 is 64 lowercase hex digits.');
        }

        [$status, $answer] = $this->ask($project, 'sha256='.$sha256);

        if (408 === $status) {
            throw new \RuntimeException(\sprintf('The Docker gate didn\'t read the new scope of "%s" in time (it has %s): see "quire-server logs gate".', $project, \is_string($answer['file_sha256'] ?? null) ? 'another file' : 'none'));
        }

        if (200 !== $status || true !== ($answer['matched'] ?? null) || !\is_string($answer['state'] ?? null)) {
            throw new \RuntimeException(\sprintf('The Docker gate gave an answer about the scope of "%s" that isn\'t one (HTTP %d).', $project, $status));
        }

        return ['state' => $answer['state'], 'reason' => \is_string($answer['reason'] ?? null) ? $answer['reason'] : null];
    }

    public function gone(string $project): void
    {
        [$status, $answer] = $this->ask($project, 'absent');

        if (200 !== $status || true !== ($answer['matched'] ?? null)) {
            throw new \RuntimeException(\sprintf('The Docker gate still had a scope for "%s" (HTTP %d): see "quire-server logs gate".', $project, $status));
        }
    }

    /**
     * @return array{int, array<string, mixed>} The HTTP status and the JSON answer
     */
    private function ask(string $project, string $query): array
    {
        $project = SiteProject::checked($project);

        if (!str_starts_with($this->dockerHost, 'unix://')) {
            throw new \RuntimeException(\sprintf('The Docker gate is asked on its trusted unix socket, not "%s".', $this->dockerHost));
        }

        $socket = @stream_socket_client($this->dockerHost, $code, $message, self::TIMEOUT);

        if (false === $socket) {
            throw new \RuntimeException(\sprintf('The Docker gate can\'t be reached on %s (%s): see "quire-server logs gate".', substr($this->dockerHost, 7), $message));
        }

        try {
            stream_set_timeout($socket, (int) self::TIMEOUT);
            // HTTP/1.0: the answer ends with the connection, never chunked.
            fwrite($socket, \sprintf("GET /_yorvogate/v1/scopes/%s?listener=%s&%s HTTP/1.0\r\nHost: gate\r\nAccept: application/json\r\n\r\n", $project, self::LISTENER, $query));
            $raw = stream_get_contents($socket, self::MAX_ANSWER + 1);
            $timedOut = stream_get_meta_data($socket)['timed_out'];
        } finally {
            fclose($socket);
        }

        if ($timedOut || false === $raw || '' === $raw) {
            throw new \RuntimeException(\sprintf('The Docker gate didn\'t answer about the scope of "%s" in time.', $project));
        }

        return self::parse($raw, $project);
    }

    /**
     * @return array{int, array<string, mixed>}
     *
     * @internal For its tests
     */
    public static function parse(string $raw, string $project): array
    {
        $parts = explode("\r\n\r\n", $raw, 2);

        if (\strlen($raw) > self::MAX_ANSWER || 2 !== \count($parts) || 1 !== preg_match('#^HTTP/1\.[01] (\d{3}) #', $parts[0], $match)) {
            throw new \RuntimeException(\sprintf('The Docker gate\'s answer about the scope of "%s" isn\'t HTTP.', $project));
        }

        try {
            $answer = json_decode($parts[1], true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf('The Docker gate\'s answer about the scope of "%s" isn\'t JSON (HTTP %s).', $project, $match[1]), $e->getCode(), previous: $e);
        }

        $answer = \is_array($answer) ? array_filter($answer, \is_string(...), \ARRAY_FILTER_USE_KEY) : null;

        if (null === $answer || ($answer['scope'] ?? null) !== $project) {
            throw new \RuntimeException(\sprintf('The Docker gate answered about another scope than "%s".', $project));
        }

        /* @var array<string, mixed> $answer */
        return [(int) $match[1], $answer];
    }
}
