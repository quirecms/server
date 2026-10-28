<?php

declare(strict_types=1);

namespace Quire\Server\ServerUpdate;

use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;

/**
 * The server's own Compose project (quire-server: front door, agent, docker-proxy, PostgreSQL), as the
 * updater acts on it: on Docker itself, since it may have to replace the docker-proxy.
 */
final readonly class ServerStack
{
    private const string PROJECT = 'quire-server';

    public function __construct(
        private ProcessRunner $runner,
        private string $dockerHost,
        private string $postgresPassword,
    ) {
    }

    /**
     * Whether a folder's Compose files, with these settings, make a valid configuration (a release's
     * files, before they replace the server's).
     *
     * @throws \RuntimeException When they don't, with Compose's reason
     */
    public function validate(string $folder, string $envFile): void
    {
        $this->compose($folder, $envFile, ['config', '--quiet'], 60)->orFail('Checking the release\'s Compose files with this server\'s settings');
    }

    public function pull(string $image): void
    {
        $this->runner->run(['docker', 'pull', '--quiet', $image], ['DOCKER_HOST' => $this->dockerHost], timeout: 1800)->orFail('Getting the release\'s image');
    }

    public function stopAgent(ServerFolder $folder): void
    {
        $this->in($folder, ['stop', 'agent'], 120)->orFail('Stopping the agent');
    }

    /**
     * Starts what changed and waits until every service is healthy.
     *
     * @param (\Closure(string): void)|null $output
     */
    public function up(ServerFolder $folder, int $wait, ?\Closure $output = null): void
    {
        $this->in($folder, ['up', '--detach', '--no-build', '--wait', '--wait-timeout', (string) $wait], $wait + 600, $output)->orFail('Starting the server');
    }

    /**
     * What the running agent says it is ("Quire Server 0.2.0").
     */
    public function version(ServerFolder $folder): string
    {
        return trim($this->in($folder, ['exec', '-T', 'agent', 'bin/console', 'server:version'], 60)->orFail('Asking the agent its version')->output);
    }

    /**
     * The registry's database, dumped to a file (PostgreSQL's custom format).
     */
    public function dumpDatabase(ServerFolder $folder, string $file): void
    {
        $dump = $this->in($folder, ['exec', '-T', '-e', 'PGPASSWORD', 'database', 'pg_dump', '-U', 'postgres', '--format=custom', 'quire_server'], 600)->orFail('Backing up the server\'s database');
        ServerFolder::writeSecret($file, $dump->output);
    }

    /**
     * The registry's database as a dump has it (the agent stopped: nothing else writes to it).
     */
    public function restoreDatabase(ServerFolder $folder, string $file): void
    {
        $this->in($folder, ['exec', '-T', '-e', 'PGPASSWORD', 'database', 'pg_restore', '-U', 'postgres', '--clean', '--if-exists', '--single-transaction', '--dbname=quire_server'], 600, inputFile: $file)->orFail('Restoring the server\'s database');
    }

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output
     */
    private function in(ServerFolder $folder, array $arguments, float $timeout, ?\Closure $output = null, ?string $inputFile = null): ProcessResult
    {
        return $this->compose($folder->path, $folder->path.'/server.env', $arguments, $timeout, $output, $inputFile);
    }

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output
     */
    private function compose(string $folder, string $envFile, array $arguments, float $timeout, ?\Closure $output = null, ?string $inputFile = null): ProcessResult
    {
        return $this->runner->run(
            ['docker', 'compose', '--project-directory', $folder, '--env-file', $envFile, '--project-name', self::PROJECT, ...$arguments],
            [
                // Only server.env sets the server's settings: Compose would take the updater's own (its
                // environment, e.g. the image it runs) over the env file's.
                ...array_fill_keys($this->inheritedSettings(), false),
                'DOCKER_HOST' => $this->dockerHost,
                'COMPOSE_ANSI' => 'never',
                'COMPOSE_PROGRESS' => 'plain',
                // The env file says which files (COMPOSE_FILE, read relative to the folder Compose runs in)
                'COMPOSE_FILE' => false,
                'COMPOSE_PROJECT_NAME' => false,
                'COMPOSE_PROFILES' => false,
                'COMPOSE_ENV_FILES' => false,
                // For pg_dump and pg_restore ("-e PGPASSWORD" passes it on): never in the arguments
                'PGPASSWORD' => $this->postgresPassword,
            ],
            $folder,
            $timeout,
            $output,
            inputFile: $inputFile,
        );
    }

    /**
     * @return list<string> The server's settings in this process's environment (QUIRE_SERVER_*), as
     *                      programs it runs would inherit them
     */
    private function inheritedSettings(): array
    {
        $names = array_keys([...getenv(), ...$_ENV, ...$_SERVER]);

        return array_values(array_filter($names, static fn (int|string $name): bool => \is_string($name) && str_starts_with($name, 'QUIRE_SERVER_')));
    }
}
