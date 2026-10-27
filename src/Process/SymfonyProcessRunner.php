<?php

declare(strict_types=1);

namespace Quire\Server\Process;

use Symfony\Component\Process\Process;

final readonly class SymfonyProcessRunner implements ProcessRunner
{
    #[\Override]
    public function run(array $command, array $env = [], ?string $directory = null, float $timeout = 600, ?\Closure $output = null, ?string $input = null): ProcessResult
    {
        $process = new Process($command, $directory, $env, $input, $timeout);
        $process->run($output instanceof \Closure ? static function (string $type, string $buffer) use ($output): void {
            $output($buffer);
        } : null);

        return new ProcessResult($command, $process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }
}
