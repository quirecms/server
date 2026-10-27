<?php

declare(strict_types=1);

namespace Quire\Server\Process;

final class ProcessFailed extends \RuntimeException
{
    public function __construct(string $what, public readonly ProcessResult $result)
    {
        $detail = trim('' !== trim($result->errorOutput) ? $result->errorOutput : $result->output);
        $lines = \array_slice(explode("\n", $detail), -15);

        parent::__construct(\sprintf('%s failed (exit code %d)%s', $what, $result->exitCode, '' === $detail ? '.' : ":\n".implode("\n", $lines)));
    }
}
