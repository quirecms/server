<?php

declare(strict_types=1);

namespace Quire\Server\Process;

/**
 * A program the server ran failed. Its message says what that means and what to do, when it's a
 * failure an owner meets (Failures); its own output's end is kept for the operation's log (detail()),
 * not shown.
 */
final class ProcessFailed extends \RuntimeException
{
    /** How much of the program's output the log keeps, and a message shows when it's not explained */
    private const int LINES = 15;

    public function __construct(string $what, public readonly ProcessResult $result)
    {
        $explained = Failures::explain($result->output."\n".$result->errorOutput);
        $detail = $this->detail();

        parent::__construct(null === $explained
            ? \sprintf('%s failed (exit code %d)%s', $what, $result->exitCode, '' === $detail ? '.' : ":\n".$detail)
            : \sprintf('%s failed: %s. %s', $what, $explained['why'], $explained['next']));
    }

    /** The end of what the program said: for the operation's log */
    public function detail(): string
    {
        $detail = trim('' !== trim($this->result->errorOutput) ? $this->result->errorOutput : $this->result->output);

        return implode("\n", \array_slice(explode("\n", $detail), -self::LINES));
    }
}
