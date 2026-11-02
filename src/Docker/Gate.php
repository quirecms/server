<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Process\ProcessRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The Docker gate (YorvoGate, gate/yorvogate.yaml), as the agent uses it. The agent's own operations
 * go to its trusted socket (DOCKER_HOST). Each site's Compose goes to a socket of the site's own, which
 * the gate opens for the site's scope file: the agent writes that file before every operation on the
 * site, from what the server allows it (its PolicyContext), so the gate checks exactly that, whatever
 * the site's files say. Who connects to a site's socket acts as that site: only the agent mounts them.
 *
 * A site's Compose runs with a Docker configuration of its own (DOCKER_CONFIG): its current context,
 * "quire", is the site's socket, and so is its builder's (Builders), since a build's image comes back
 * through the current context. Nothing of a site's runs on the trusted socket but putting back a site
 * exactly as it was before the server adopted it (Compose::restoreAsBefore).
 */
final readonly class Gate
{
    /** The context a site's Docker configuration uses: the site's socket */
    public const string CONTEXT = 'quire';

    /** The label that says which project (and so which site) a Docker object belongs to */
    public const string OWNER_LABEL = 'com.docker.compose.project';

    /** How long the gate takes at most to see a scope file that changed (it looks every 2 seconds) */
    private const float RELOAD_SECONDS = 2.5;

    /** How long a site's socket may take to open */
    private const float OPEN_SECONDS = 15.0;

    private Filesystem $filesystem;

    public function __construct(
        private ProcessRunner $runner,
        /** The trusted socket, e.g. "unix:///run/quire-gate/trusted/trusted.sock" */
        private string $dockerHost,
        /** Where the agent writes the scope files the gate reads */
        private string $scopes,
        /** Where the gate opens each site's socket */
        private string $sockets,
        /** Where each site's Docker configuration is kept (the agent's own, never a site's) */
        private string $configs,
        /** Waiting for the gate (tests don't) */
        private float $reloadSeconds = self::RELOAD_SECONDS,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * The agent's own operations: the trusted socket.
     *
     * @return array<string, string|false>
     */
    public function trusted(): array
    {
        return ['DOCKER_HOST' => $this->dockerHost, 'DOCKER_CONFIG' => false, 'DOCKER_CONTEXT' => false];
    }

    /**
     * The site's scope, as the gate must have it before anything is done for the site: written (when
     * it changed) and in effect, its socket open, its Docker configuration ready.
     *
     * @return array<string, string|false> The environment Docker's CLI runs in for the site
     */
    public function site(SiteProject $project): array
    {
        $name = $project->name();

        if ($this->write($project)) {
            // An open socket serves the scope the gate read before: it reads the new one at its next look.
            usleep((int) ($this->reloadSeconds * 1_000_000));
        }

        $this->waitForSocket($name);

        return $this->configuration($name);
    }

    /**
     * The site's scope gone, with its socket (the gate closes it) and its Docker configuration.
     */
    public function remove(string $project): void
    {
        // A path of its own only
        SiteProject::checked($project);
        $this->filesystem->remove([$this->scopeFile($project), $this->configs.'/'.$project]);
    }

    /**
     * At the agent's start: the scopes of these sites (the registry's), and no others. A scope left by a
     * site that's gone would keep a socket open that acts as it. A site whose scope can't be made is
     * said, and the others go on: until it's fixed, nothing can act as that site.
     *
     * @param list<SiteProject> $projects
     *
     * @return array{removed: list<string>, failed: array<string, string>} The scopes removed, and the sites without one (why)
     */
    public function reconcile(array $projects): array
    {
        $keep = [];

        foreach ($projects as $project) {
            $keep[$project->name()] = $project;
        }

        $removed = [];

        foreach ($this->glob($this->scopes.'/*.json') as $file) {
            $name = basename($file, '.json');

            if (!isset($keep[$name])) {
                $this->filesystem->remove($file);
                $removed[] = $name;
            }
        }

        foreach ($this->glob($this->configs.'/*', \GLOB_ONLYDIR) as $folder) {
            if (!isset($keep[basename($folder)])) {
                $this->filesystem->remove($folder);
            }
        }

        // Every file first, then one wait for the gate: not one per site.
        $failed = [];
        $changed = false;

        foreach ($keep as $name => $project) {
            try {
                $changed = $this->write($project) || $changed;
            } catch (\Throwable $e) {
                $failed[$name] = $e->getMessage();
            }
        }

        if ($changed) {
            usleep((int) ($this->reloadSeconds * 1_000_000));
        }

        foreach (array_keys($keep) as $name) {
            if (!isset($failed[$name])) {
                try {
                    $this->waitForSocket($name);
                    $this->configuration($name);
                } catch (\Throwable $e) {
                    $failed[$name] = $e->getMessage();
                }
            }
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    /**
     * Writes the site's scope file when it changed.
     *
     * @return bool Whether the gate had read an earlier one (its socket is open): it reads the new one
     *              within RELOAD_SECONDS
     */
    private function write(SiteProject $project): bool
    {
        $name = SiteProject::checked($project->name());
        $file = $this->scopeFile($name);
        $document = json_encode($this->scope($project), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT)."\n";
        $before = is_file($file) ? file_get_contents($file) : null;

        if ($before === $document) {
            return false;
        }

        $this->filesystem->mkdir($this->scopes, 0o700);
        // Whole or not at all: the gate may read it at any moment.
        $temporary = $this->scopes.'/.'.$name.'.'.bin2hex(random_bytes(4));
        $this->filesystem->dumpFile($temporary, $document);
        // Only its owner may change it, or the gate refuses it.
        $this->filesystem->chmod($temporary, 0o600);
        $this->filesystem->rename($temporary, $file, true);

        return null !== $before && $this->isSocket($this->socket($name));
    }

    /**
     * Waits until the gate's trusted socket is open (the agent may start before the gate has).
     */
    public function waitForTrusted(float $seconds = 60.0): void
    {
        $socket = str_starts_with($this->dockerHost, 'unix://') ? substr($this->dockerHost, 7) : null;

        if (null === $socket) {
            return;
        }

        $deadline = microtime(true) + $seconds;

        while (!$this->isSocket($socket)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(\sprintf('The Docker gate didn\'t open its socket (%s): see "quire-server logs gate".', $socket));
            }

            usleep(250_000);
        }
    }

    /**
     * What releases before the gate left: the docker-proxy (Docker's API with no look at the requests'
     * bodies, so root for whoever reached it) and its network. An update by an earlier release's
     * updater starts the gate but leaves those running.
     *
     * @return bool Whether there was something to remove
     */
    public function removeDockerProxy(): bool
    {
        $env = $this->trusted();
        $ids = trim($this->runner->run(['docker', 'container', 'ls', '--all', '--quiet', '--filter', 'label='.self::OWNER_LABEL.'=quire-server', '--filter', 'label=com.docker.compose.service=docker-proxy'], $env, timeout: 60)->orFail('Looking for the docker-proxy of an earlier release')->output);

        if ('' !== $ids) {
            $this->runner->run(['docker', 'container', 'rm', '--force', ...explode("\n", $ids)], $env, timeout: 120)->orFail('Removing the docker-proxy of an earlier release');
        }

        $network = $this->runner->run(['docker', 'network', 'inspect', '--format', '{{.Name}}', 'quire-docker'], $env, timeout: 60)->successful();

        if ($network) {
            $this->runner->run(['docker', 'network', 'rm', 'quire-docker'], $env, timeout: 60)->orFail('Removing the docker-proxy\'s network');
        }

        return '' !== $ids || $network;
    }

    /**
     * What the gate allows the site, from what the server allows it:
     *   - everything that carries its project's label is its own, and what it creates must carry it;
     *   - the names it creates start with "<project>-" (containers) or "<project>_" (networks,
     *     volumes): Compose's names. Sites' names nest ("jane", "jane-blog"): the longest prefix wins;
     *   - its images are "quire-sites/<project>/…", which no other site may use;
     *   - on the server's shared networks, only the aliases Compose gives its containers there (the
     *     service's name and the container's) and those the policy allows (its domains');
     *   - its BuildKit builder, which it may only reach to build (Builders).
     *
     * @return array<string, mixed>
     */
    public function scope(SiteProject $project): array
    {
        $name = $project->name();
        $networks = [];

        foreach ($project->policy->sharedNetworks as $network => $services) {
            $aliases = [];

            foreach ($services as $service => $allowed) {
                array_push($aliases, $service, $name.'-'.$service.'-1', ...$allowed);
            }

            if ([] !== $aliases) {
                $aliases = array_values(array_unique($aliases));
                sort($aliases);
                $networks[$network] = ['aliases' => $aliases];
            }
        }

        return [
            'owner' => ['label' => self::OWNER_LABEL, 'value' => $name],
            'prefixes' => [$name.'-', $name.'_'],
            'images' => [PolicyContext::IMAGES.$name.'/'],
            ...([] === $networks ? [] : ['networks' => $networks]),
            'builders' => [Builders::container($name)],
        ];
    }

    /**
     * The site's Docker configuration: its "quire" context, the site's socket, is the current one.
     *
     * @return array<string, string|false>
     */
    private function configuration(string $project): array
    {
        $folder = $this->configs.'/'.$project;

        // As "docker context create" and "docker context use" write them (docker/cli's context store),
        // without a program run for each operation.
        $meta = $folder.'/contexts/meta/'.hash('sha256', self::CONTEXT).'/meta.json';
        $context = json_encode(['Name' => self::CONTEXT, 'Metadata' => new \stdClass(), 'Endpoints' => ['docker' => ['Host' => 'unix://'.$this->socket($project), 'SkipTLSVerify' => false]]], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $config = json_encode(['currentContext' => self::CONTEXT], \JSON_THROW_ON_ERROR);

        if (!is_file($meta) || file_get_contents($meta) !== $context || !is_file($folder.'/config.json') || file_get_contents($folder.'/config.json') !== $config) {
            $this->filesystem->mkdir($folder, 0o700);
            $this->filesystem->dumpFile($meta, $context);
            $this->filesystem->dumpFile($folder.'/config.json', $config);
        }

        return ['DOCKER_CONFIG' => $folder, 'DOCKER_HOST' => false, 'DOCKER_CONTEXT' => false, 'BUILDX_BUILDER' => false];
    }

    private function waitForSocket(string $project): void
    {
        $socket = $this->socket($project);
        $deadline = microtime(true) + self::OPEN_SECONDS;

        while (!$this->isSocket($socket)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(\sprintf('The Docker gate didn\'t open the socket of "%s": see "quire-server logs gate" for why it refused its scope.', $project));
            }

            usleep(100_000);
        }
    }

    /**
     * @return list<string>
     */
    private function glob(string $pattern, int $flags = 0): array
    {
        $found = glob($pattern, $flags);

        return false === $found ? [] : $found;
    }

    private function isSocket(string $path): bool
    {
        clearstatcache(true, $path);

        return 'socket' === @filetype($path);
    }

    private function scopeFile(string $project): string
    {
        return $this->scopes.'/'.$project.'.json';
    }

    private function socket(string $project): string
    {
        return $this->sockets.'/'.$project.'.sock';
    }
}
