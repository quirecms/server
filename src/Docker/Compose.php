<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;

/**
 * Docker Compose for sites, through the docker-proxy. Building and starting always check the site's
 * whole configuration against the policy first: there's no way to start a site without it.
 */
final readonly class Compose
{
    public function __construct(
        private ProcessRunner $runner,
        private ComposePolicy $policy,
        private string $dockerHost,
    ) {
    }

    /**
     * The site's configuration, all files merged, as the policy reads it.
     *
     * @return array<mixed>
     */
    public function config(SiteProject $project): array
    {
        $result = $this->compose($project, ['config', '--format', 'json', '--no-env-resolution'], timeout: 60)->orFail('Reading the Compose configuration');

        try {
            $config = json_decode($result->output, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Docker Compose printed a configuration that isn\'t JSON.', $e->getCode(), previous: $e);
        }

        if (!\is_array($config)) {
            throw new \RuntimeException('Docker Compose printed a configuration that isn\'t an object.');
        }

        return $config;
    }

    /**
     * @throws PolicyViolation When the configuration isn't allowed
     */
    public function check(SiteProject $project): void
    {
        $violations = $this->policy->violations($this->config($project), $project->policy);

        if ([] !== $violations) {
            throw new PolicyViolation($project->name(), $violations);
        }
    }

    /**
     * @param (\Closure(string): void)|null $output
     */
    public function build(SiteProject $project, ?\Closure $output = null): void
    {
        $this->check($project);
        $this->compose($project, ['build', '--pull'], timeout: 1800, output: $output)->orFail('Building the site');
    }

    /**
     * Starts the site and waits until its containers are healthy.
     *
     * @param (\Closure(string): void)|null $output
     */
    public function up(SiteProject $project, ?\Closure $output = null): void
    {
        $this->check($project);
        $this->compose($project, ['up', '--detach', '--wait', '--wait-timeout', '600', '--remove-orphans', '--no-build'], timeout: 900, output: $output)->orFail('Starting the site');
    }

    /**
     * Starts a site with the configuration it had before the server changed it, unchecked: only to put
     * back exactly what was running before (e.g. when adopting it failed), never anything new. Its
     * env file must be the copy the server kept of the one it had.
     *
     * @param (\Closure(string): void)|null $output
     */
    public function restoreAsBefore(SiteProject $project, string $envFileBefore, ?\Closure $output = null): void
    {
        $before = new SiteProject($project->directory, $envFileBefore, $project->policy);
        $this->compose($before, ['up', '--detach', '--wait', '--wait-timeout', '300', '--remove-orphans', '--no-build'], timeout: 600, output: $output)->orFail('Starting the site as it was');
    }

    /**
     * @param (\Closure(string): void)|null $output
     */
    public function down(SiteProject $project, bool $volumes, ?\Closure $output = null): void
    {
        $this->compose($project, ['down', '--remove-orphans', ...($volumes ? ['--volumes'] : [])], timeout: 300, output: $output)->orFail('Stopping the site');
    }

    /**
     * Runs a command in one of the site's running containers.
     *
     * @param list<string>                  $command
     * @param (\Closure(string): void)|null $output
     */
    public function exec(SiteProject $project, string $service, array $command, ?\Closure $output = null, float $timeout = 600, ?string $inputFile = null): ProcessResult
    {
        return $this->compose($project, ['exec', '-T', $service, ...$command], timeout: $timeout, output: $output, inputFile: $inputFile);
    }

    /**
     * Copies a file out of one of the site's containers.
     */
    public function copyFrom(SiteProject $project, string $service, string $source, string $destination): void
    {
        $this->compose($project, ['cp', $service.':'.$source, $destination], timeout: 3600)->orFail('Copying out of the site');
    }

    /**
     * The image a service of the site runs (e.g. "jane-php-prod"), as its configuration names it.
     */
    public function image(SiteProject $project, string $service): string
    {
        $config = $this->config($project);
        $services = \is_array($config['services'] ?? null) ? $config['services'] : [];
        $image = \is_array($services[$service] ?? null) ? ($services[$service]['image'] ?? null) : null;

        return \is_string($image) && '' !== $image ? $image : throw new \RuntimeException(\sprintf('The site\'s "%s" service names no image.', $service));
    }

    /**
     * The state of each of the site's containers.
     *
     * @return list<array{service: string, state: string, health: string}>
     */
    public function ps(SiteProject $project): array
    {
        $result = $this->compose($project, ['ps', '--all', '--format', 'json'], timeout: 60);

        if (!$result->successful()) {
            return [];
        }

        $containers = [];

        // One JSON object per line.
        foreach (explode("\n", trim($result->output)) as $line) {
            $container = '' === $line ? null : json_decode($line, true);

            if (\is_array($container)) {
                $containers[] = [
                    'service' => \is_string($container['Service'] ?? null) ? $container['Service'] : '',
                    'state' => \is_string($container['State'] ?? null) ? $container['State'] : '',
                    'health' => \is_string($container['Health'] ?? null) ? $container['Health'] : '',
                ];
            }
        }

        return $containers;
    }

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output
     */
    private function compose(SiteProject $project, array $arguments, float $timeout, ?\Closure $output = null, ?string $inputFile = null): ProcessResult
    {
        return $this->runner->run(
            ['docker', 'compose', '--project-directory', $project->directory, '--env-file', $project->envFile, '--project-name', $project->name(), ...$arguments],
            [
                'DOCKER_HOST' => $this->dockerHost,
                'COMPOSE_ANSI' => 'never',
                'COMPOSE_PROGRESS' => 'plain',
                // Only the env file says which files and project: nothing inherited.
                'COMPOSE_FILE' => false,
                'COMPOSE_PROJECT_NAME' => false,
                'COMPOSE_PROFILES' => false,
                'COMPOSE_ENV_FILES' => false,
            ],
            $project->directory,
            $timeout,
            $output,
            inputFile: $inputFile,
        );
    }
}
