<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

/**
 * A long step's heartbeat (M-20, NOVICE-01): while a build runs, the owner hears every half minute that
 * it does, for how long, and at which of its steps (BuildKit's "#12 [php 5/9] RUN composer install"),
 * rather than nothing for half an hour. The program's output goes on to the log as it came.
 */
final class Progress
{
    /** How often the owner hears of it, in seconds */
    public const int EVERY = 30;

    private ?string $step = null;
    private int $said;

    /**
     * @param \Closure(string): void $output Where the program's output goes (the operation's log)
     * @param \Closure(string): void $say    What the owner reads
     * @param \Closure(): int|null   $now
     */
    private function __construct(
        private readonly \Closure $output,
        private readonly \Closure $say,
        private readonly string $doing,
        private readonly int $started,
        private readonly ?\Closure $now,
    ) {
        $this->said = $started;
    }

    /**
     * The output closure to give the program: it's called with each chunk of its output, and with
     * nothing while it's quiet (the runner's tick).
     *
     * @param \Closure(string): void $output
     * @param \Closure(string): void $say
     * @param \Closure(): int|null   $now
     *
     * @return \Closure(string): void
     */
    public static function of(\Closure $output, \Closure $say, string $doing = 'Still building', ?\Closure $now = null): \Closure
    {
        $progress = new self($output, $say, $doing, $now instanceof \Closure ? $now() : time(), $now);

        return $progress->chunk(...);
    }

    public function chunk(string $chunk): void
    {
        if ('' !== $chunk) {
            ($this->output)($chunk);

            if (preg_match_all('/^#\d+ \[([^\]]+)\] (.+)$/m', $chunk, $matches) > 0) {
                $this->step = trim($matches[1][\count($matches[1]) - 1].': '.$matches[2][\count($matches[2]) - 1]);
            }
        }

        $now = $this->now instanceof \Closure ? ($this->now)() : time();

        if ($now - $this->said < self::EVERY) {
            return;
        }

        $this->said = $now;
        $elapsed = $now - $this->started;
        $step = null === $this->step ? '' : ', at '.(mb_strlen($this->step) > 80 ? mb_substr($this->step, 0, 79).'…' : $this->step);
        ($this->say)(\sprintf('%s (%s so far%s)', $this->doing, $elapsed >= 120 ? intdiv($elapsed, 60).' min' : $elapsed.' s', $step));
    }
}
