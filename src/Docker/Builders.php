<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Process\ProcessRunner;

/**
 * Each site's BuildKit builder: a container of its own, so a site's build cache ("RUN --mount=type=
 * cache") can't be read or poisoned by another site's build. The agent creates it on the gate's
 * trusted socket, and the site reaches it through its own socket, with buildx's "remote" driver: the
 * gate lets a site only inspect its builder and run "buildctl dial-stdio" in it, and only while the
 * builder is as created here.
 *
 * BuildKit runs rootless: as user 1000, not privileged, with no capabilities, no host paths and no
 * ports. It needs a user namespace of its own, so seccomp and the kernel's masked /proc paths are left
 * to it (the gate allows exactly these options), and on Ubuntu 23.10 and later an AppArmor profile that
 * grants it one (gate/host-setup.sh loads it). Not "no-new-privileges": newuidmap needs its file
 * capabilities to map its users. It's on the sandbox network: the internet (base images, packages),
 * none of the server's networks or sites, nor Docker's default bridge.
 *
 * A builder runs only while its site builds: started for the build, its cache trimmed to CACHE after
 * it, then stopped. Idle, a hundred builders held more memory than the hundred sites they build
 * (about 110 MiB each), and their caches grew by 1.5 GB a site with nothing to trim them.
 */
final readonly class Builders
{
    /** BuildKit's rootless image, by digest: Dependabot can't see it here, so it's bumped by hand */
    public const string BUILDKIT = 'moby/buildkit:v0.34.1-rootless@sha256:f68752a5a94acfcdad2d07867b7075601a421638e17fc5f28efc1c814d0c4f89';

    /** The builder's name in a site's Docker configuration */
    public const string NAME = 'site';

    /** The label that says whose builder a container is */
    public const string LABEL = 'com.quirecms.server.builder';

    /** The label that says which BuildKit a builder was created with */
    private const string IMAGE_LABEL = 'com.quirecms.server.buildkit';

    /** The AppArmor profiles a builder may run under (the gate's builder_apparmor_profiles) */
    public const array APPARMOR = ['yorvogate-buildkit-rootless', 'unconfined'];

    /** The most processes a build may run */
    private const int PIDS = 4096;

    /**
     * The most build cache a site keeps between builds: the newest, so a rebuild still finds its
     * packages' downloads and its unchanged layers. Untrimmed, a site's grew past 1.5 GB.
     */
    public const string CACHE = '512mb';

    /** How long a builder may take to answer, once started */
    private const int START_SECONDS = 60;

    public function __construct(
        private ProcessRunner $runner,
        private SandboxNetwork $network,
        private string $dockerHost,
        /** What host-setup found: the AppArmor profile builders run under ("none": the host has no AppArmor) */
        private string $facts,
    ) {
    }

    /**
     * The builder's container for a site: not one of the site's own names (those start with
     * "<project>-"), as the site may only build with it.
     */
    public static function container(string $project): string
    {
        return 'quire-buildkit-'.SiteProject::checked($project);
    }

    /**
     * The site's builder, running BUILDKIT, and registered in the site's Docker configuration.
     *
     * @param array<string, string|false>   $site   The environment of the site's Docker configuration (Gate::site())
     * @param (\Closure(string): void)|null $output
     *
     * @return string The builder's name for "docker compose build --builder"
     */
    public function ensure(SiteProject $project, array $site, ?\Closure $output = null): string
    {
        $container = self::container($project->name());
        // Which BuildKit it runs, as created here, and whether it runs
        $state = trim($this->docker(['container', 'inspect', '--format', '{{index .Config.Labels "'.self::IMAGE_LABEL.'"}} {{.State.Running}}', $container], 60)->output);

        if ('' !== $state && self::BUILDKIT.' true' !== $state && self::BUILDKIT.' false' !== $state) {
            // An older BuildKit: a new builder (its cache goes with the old one).
            $this->remove($project->name());
            $state = '';
        }

        if ('' === $state) {
            $this->create($project->name(), $output);
        } elseif (self::BUILDKIT.' false' === $state) {
            $this->docker(['container', 'start', $container], 120)->orFail('Starting the site\'s builder');
        }

        if (!$this->runner->run(['docker', 'buildx', 'inspect', self::NAME], $site, timeout: 60)->successful()) {
            $this->runner->run(['docker', 'buildx', 'create', '--name', self::NAME, '--driver', 'remote', 'docker-container://'.$container.'?context='.Gate::CONTEXT], $site, timeout: 60)->orFail('Registering the site\'s builder');
        }

        // BuildKit answers a moment after its container starts.
        $deadline = time() + self::START_SECONDS;

        while (!($answer = $this->runner->run(['docker', 'buildx', 'inspect', '--bootstrap', self::NAME], $site, timeout: 60))->successful()) {
            if (time() > $deadline) {
                $answer->orFail('Reaching the site\'s builder');
            }

            sleep(1);
        }

        return self::NAME;
    }

    /**
     * After a build, whatever its outcome: the site's cache trimmed to CACHE and its builder stopped,
     * until its next build starts it again. Neither failing fails the build: what's said is returned.
     *
     * @param array<string, string|false> $site The environment of the site's Docker configuration (Gate::site())
     *
     * @return list<string> What didn't work, said for the person who asked
     */
    public function release(SiteProject $project, array $site): array
    {
        $notes = [];
        $trimmed = $this->runner->run(['docker', 'buildx', 'prune', '--builder', self::NAME, '--force', '--max-used-space', self::CACHE], $site, timeout: 300);

        if (!$trimmed->successful()) {
            $notes[] = \sprintf('The site\'s build cache wasn\'t trimmed (%s): its next build tries again.', $this->lastLine($trimmed));
        }

        $stopped = $this->docker(['container', 'stop', '--time', '30', self::container($project->name())], 120);

        if (!$stopped->successful()) {
            $notes[] = \sprintf('The site\'s builder wasn\'t stopped (%s): it holds memory until the agent starts again.', $this->lastLine($stopped));
        }

        return $notes;
    }

    /**
     * Builders left running, e.g. by a release before builders stopped after their builds, or by a
     * build the agent's stop cut short: stopped. Only at the agent's start, when no build can run (each
     * runs in the agent's container).
     *
     * @return list<string> The containers stopped
     */
    public function stopIdle(): array
    {
        $listed = $this->docker(['container', 'ls', '--filter', 'label='.self::LABEL, '--format', '{{.Names}}'], 60)->orFail('Listing the sites\' builders');
        $stopped = [];

        foreach (explode("\n", trim($listed->output)) as $container) {
            if (1 !== preg_match('/^quire-buildkit-[a-z0-9][a-z0-9-]*\z/', $container)) {
                continue;
            }

            $this->docker(['container', 'stop', '--time', '30', $container], 120)->orFail('Stopping a site\'s idle builder');
            $stopped[] = $container;
        }

        return $stopped;
    }

    /**
     * Removes the site's builder and its cache.
     */
    public function remove(string $project): void
    {
        $container = self::container($project);
        $this->docker(['container', 'rm', '--force', $container], 120)->orFail('Removing the site\'s builder');
        $this->docker(['volume', 'rm', '--force', $container.'-state'], 60)->orFail('Removing the site\'s build cache');
    }

    /**
     * Builders of releases before the gate (buildx's "docker-container" driver: privileged): removed
     * with their caches.
     *
     * @return list<string> The containers removed
     */
    public function removeLegacy(): array
    {
        $removed = [];
        $listed = $this->docker(['container', 'ls', '--all', '--filter', 'name=^buildx_buildkit_quire-', '--format', '{{.Names}}'], 60)->orFail('Listing the builders of earlier releases');

        foreach (explode("\n", trim($listed->output)) as $container) {
            if (1 !== preg_match('/^buildx_buildkit_quire-[a-z0-9_-]+-[0-9a-f]{12}0\z/', $container)) {
                continue;
            }

            $this->docker(['container', 'rm', '--force', '--volumes', $container], 120)->orFail('Removing a builder of an earlier release');
            $this->docker(['volume', 'rm', '--force', $container.'_state'], 60)->orFail('Removing a build cache of an earlier release');
            $removed[] = $container;
        }

        return $removed;
    }

    /**
     * @param (\Closure(string): void)|null $output
     */
    private function create(string $project, ?\Closure $output): void
    {
        $container = self::container($project);
        $apparmor = $this->apparmor();
        $this->docker([
            'container', 'run', '--detach', '--name', $container, '--label', self::LABEL.'='.$project, '--label', self::IMAGE_LABEL.'='.self::BUILDKIT,
            '--network', $this->network->ensure(),
            '--security-opt', 'seccomp=unconfined', '--security-opt', 'systempaths=unconfined',
            // Said, not left to Docker's default: a hardened daemon ("no-new-privileges": true, CIS
            // Docker 2.18) would otherwise take newuidmap's file capabilities, and the builder exits
            '--security-opt', 'no-new-privileges=false',
            ...(null === $apparmor ? [] : ['--security-opt', 'apparmor='.$apparmor]),
            '--pids-limit', (string) self::PIDS,
            '--volume', $container.'-state:/home/user/.local/share/buildkit',
            self::BUILDKIT,
        ], 600, $output)->orFail('Starting the site\'s builder');
    }

    /**
     * The AppArmor profile builders run under, as host-setup found it, or null when the host has no
     * AppArmor.
     */
    private function apparmor(): ?string
    {
        $found = is_file($this->facts) ? trim((string) file_get_contents($this->facts)) : '';

        return match (true) {
            'none' === $found => null,
            \in_array($found, self::APPARMOR, true) => $found,
            default => throw new \RuntimeException(\sprintf('The host\'s setup for builds is missing or unknown (%s): see "quire-server logs host-setup".', '' === $found ? 'nothing in '.$this->facts : '"'.$found.'"')),
        };
    }

    private function lastLine(\Quire\Server\Process\ProcessResult $result): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $result->errorOutput."\n".$result->output)), static fn (string $line): bool => '' !== $line));

        return [] === $lines ? 'no reason given' : $lines[\count($lines) - 1];
    }

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output
     */
    private function docker(array $arguments, float $timeout, ?\Closure $output = null): \Quire\Server\Process\ProcessResult
    {
        return $this->runner->run(['docker', ...$arguments], ['DOCKER_HOST' => $this->dockerHost], timeout: $timeout, output: $output);
    }
}
