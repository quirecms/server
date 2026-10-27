<?php

declare(strict_types=1);

namespace Quire\Server\Tests;

use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;

/**
 * Programs as tests script them: what each prints, by its first words ("composer update",
 * "docker compose up", "docker image tag"), and every command run, with its environment.
 */
final class ScriptedRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, env: array<string, string|false>, directory: ?string, inputFile: ?string}> */
    public array $runs = [];

    /**
     * @param array<string, \Closure(list<string>, ?string, ?string): ProcessResult> $script What a command prints, given it, its folder and the file on its input
     */
    public function __construct(
        public array $script = [],
    ) {
    }

    #[\Override]
    public function run(array $command, array $env = [], ?string $directory = null, float $timeout = 600, ?\Closure $output = null, ?string $input = null, ?string $inputFile = null): ProcessResult
    {
        $this->runs[] = ['command' => $command, 'env' => $env, 'directory' => $directory, 'inputFile' => $inputFile];
        $key = self::key($command);
        $result = ($this->script[$key] ?? throw new \LogicException('Unexpected command: '.implode(' ', $command)))($command, $directory, $inputFile);

        if ($output instanceof \Closure) {
            $output($result->output.$result->errorOutput);
        }

        return $result;
    }

    /**
     * @return list<string> The keys of the commands run, in order
     */
    public function keys(): array
    {
        return array_map(static fn (array $run): string => self::key($run['command']), $this->runs);
    }

    /**
     * @param list<string> $command
     */
    public static function ok(array $command, string $output = ''): ProcessResult
    {
        return new ProcessResult($command, 0, $output, '');
    }

    /**
     * @param list<string> $command
     */
    public static function failed(array $command, string $error): ProcessResult
    {
        return new ProcessResult($command, 1, '', $error);
    }

    /**
     * Git's subcommand, after its options ("-C dir", "-c name=value").
     *
     * @param list<string> $command
     */
    public static function gitSubcommand(array $command): string
    {
        for ($i = 1, $count = \count($command); $i < $count; ++$i) {
            if (\in_array($command[$i], ['-C', '-c'], true)) {
                ++$i;

                continue;
            }

            return $command[$i];
        }

        return '';
    }

    /**
     * "docker compose <sub>" (after its options), "docker image <sub>", or the program and its first argument.
     *
     * @param list<string> $command
     */
    public static function key(array $command): string
    {
        if ('docker' === $command[0] && 'compose' === ($command[1] ?? '')) {
            return 'docker compose '.($command[8] ?? '');
        }

        if ('git' === $command[0]) {
            return 'git '.self::gitSubcommand($command);
        }

        return $command[0].' '.($command[1] ?? '').('docker' === $command[0] ? ' '.($command[2] ?? '') : '');
    }
}
