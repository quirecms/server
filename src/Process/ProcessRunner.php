<?php

declare(strict_types=1);

namespace Quire\Server\Process;

/**
 * Runs a program: always as a list of arguments, never through a shell, so nothing in a name or a
 * domain is ever interpreted. Secrets go in the environment or in files, never in the arguments,
 * which every process on the machine can read.
 */
interface ProcessRunner
{
    /**
     * @param list<string>                  $command The program and its arguments
     * @param array<string, string|false>   $env     Variables added to the runner's own environment; false removes one
     * @param (\Closure(string): void)|null $output  Gets the output as it comes, e.g. to log it
     */
    public function run(array $command, array $env = [], ?string $directory = null, float $timeout = 600, ?\Closure $output = null, ?string $input = null): ProcessResult;
}
