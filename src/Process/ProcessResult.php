<?php

declare(strict_types=1);

namespace Quire\Server\Process;

final readonly class ProcessResult
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        public array $command,
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {
    }

    public function successful(): bool
    {
        return 0 === $this->exitCode;
    }

    /**
     * @throws ProcessFailed When it didn't succeed
     */
    public function orFail(string $what): self
    {
        if (!$this->successful()) {
            throw new ProcessFailed($what, $this);
        }

        return $this;
    }
}
