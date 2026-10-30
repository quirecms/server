<?php

declare(strict_types=1);

namespace Quire\Server\Process;

use Symfony\Component\Process\Process;

final readonly class SymfonyProcessRunner implements ProcessRunner
{
    /**
     * What a program gets of the agent's own environment: enough to run, nothing that configures or
     * authenticates anything.
     */
    private const array INHERITED = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'TZ', 'TMPDIR'];

    #[\Override]
    public function run(array $command, array $env = [], ?string $directory = null, float $timeout = 600, ?\Closure $output = null, ?string $input = null, ?string $inputFile = null): ProcessResult
    {
        $stream = null;

        if (null !== $inputFile) {
            // The agent's own files only: a link, e.g. in a backup from elsewhere, could point at any.
            if (is_link($inputFile)) {
                throw new \RuntimeException(\sprintf('"%s" is a link: not read.', $inputFile));
            }

            $stream = @fopen($inputFile, 'r');

            if (false === $stream) {
                throw new \RuntimeException(\sprintf('Couldn\'t read "%s".', $inputFile));
            }
        }

        try {
            $process = new Process($command, $directory, $this->environment($env), $stream ?? $input, $timeout);
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

    /**
     * The program's whole environment: the basics above, and what the caller gives. The rest of the
     * agent's environment is removed, as Symfony's Process would pass it on: it holds the server's
     * secrets (the PostgreSQL superuser's address, the backup keys, APP_SECRET), and Compose fills in a
     * site's "${NAME}" from it, ahead of the site's own env file.
     *
     * @param array<string, string|false> $env
     *
     * @return array<string, string|false>
     */
    private function environment(array $env): array
    {
        $removed = [];

        foreach (array_keys([...getenv(), ...$_ENV, ...$_SERVER]) as $name) {
            if (\is_string($name) && !\in_array($name, self::INHERITED, true)) {
                $removed[$name] = false;
            }
        }

        return [...$removed, ...$env];
    }
}
