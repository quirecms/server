<?php

declare(strict_types=1);

namespace Quire\Server\Process;

use Symfony\Component\Process\Process;

final readonly class SymfonyProcessRunner implements ProcessRunner
{
    #[\Override]
    public function run(array $command, array $env = [], ?string $directory = null, float $timeout = 600, ?\Closure $output = null, ?string $input = null, ?string $inputFile = null): ProcessResult
    {
        $stream = null;

        if (null !== $inputFile) {
            $stream = @fopen($inputFile, 'r');

            if (false === $stream) {
                throw new \RuntimeException(\sprintf('Couldn\'t read "%s".', $inputFile));
            }
        }

        try {
            $process = new Process($command, $directory, $env, $stream ?? $input, $timeout);
            $process->run($output instanceof \Closure ? static function (string $type, string $buffer) use ($output): void {
                $output($buffer);
            } : null);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return new ProcessResult($command, $process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }
}
