<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

use Quire\Server\Site\SiteName;

/**
 * One operation as it runs: its log, scrubbed of its secrets, and its outcome.
 */
final class Operation
{
    /** At most this many lines are kept: a runaway build can't fill the database. */
    public const int MAX_LINES = 5000;
    private const int MAX_LINE_LENGTH = 2000;

    /** @var list<string> */
    private array $secrets = [];
    private int $seq = 0;
    private string $partial = '';
    private bool $finished = false;

    /**
     * @param \Closure(string, int, string): void   $append
     * @param \Closure(string, bool, ?string): void $finish
     */
    public function __construct(
        public readonly string $id,
        public readonly string $action,
        public readonly ?SiteName $site,
        private readonly \Closure $append,
        private readonly \Closure $finish,
    ) {
    }

    /**
     * A value never to be written to the log (a password, a secret): replaced wherever it appears.
     */
    public function secret(string $value): void
    {
        if (\strlen($value) >= 6) {
            $this->secrets[] = $value;
        }
    }

    /**
     * Output as it comes: whole lines are recorded, a line's end waits for the next part.
     */
    public function output(string $chunk): void
    {
        $lines = explode("\n", $this->partial.$chunk);
        $this->partial = array_pop($lines);

        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    public function line(string $line): void
    {
        if ($this->seq > self::MAX_LINES) {
            return;
        }

        if (self::MAX_LINES === $this->seq) {
            $line = \sprintf('(more than %d lines: the rest isn\'t kept)', self::MAX_LINES);
        }

        ($this->append)($this->id, $this->seq++, $this->clean($line));
    }

    public function succeed(): void
    {
        $this->end(true, null);
    }

    public function fail(string $error): void
    {
        $this->end(false, $this->clean($error, 4000));
    }

    public function scrub(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, '[secret]', $text);
        }

        return $text;
    }

    private function end(bool $succeeded, ?string $error): void
    {
        if ($this->finished) {
            return;
        }

        if ('' !== $this->partial) {
            $this->line($this->partial);
            $this->partial = '';
        }

        $this->finished = true;
        ($this->finish)($this->id, $succeeded, $error);
    }

    /**
     * Without the secrets, terminal escape codes and other control characters, and not too long.
     */
    private function clean(string $text, int $length = self::MAX_LINE_LENGTH): string
    {
        $text = $this->scrub($text);
        $text = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e[@-_]/', '', $text);
        $text = (string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', rtrim($text, "\r"));
        // Again: a secret that escape codes split is whole now.
        $text = $this->scrub($text);

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }
}
