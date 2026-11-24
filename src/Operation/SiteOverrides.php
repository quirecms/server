<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

use Quire\Server\Docker\Compose;
use Quire\Server\Docker\Gate;
use Quire\Server\Docker\Images;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Network\SiteLinks;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;

/**
 * Every site's compose.server.yaml as this release writes it, at each start of the agent: a release
 * may harden sites further, or name things anew (their images are named "quire-sites/<project>/…" since
 * 0.3.1). The image a site runs gets its name too when it has none yet, so its next update can still be
 * undone. A running site whose override changed is started again with it (a few seconds), once; a
 * failed one gets it for its next start.
 *
 * This is also how a server of a release before D-34 moves its sites to their links: each gets its
 * addresses and its link, its override without the shared networks, and its "up" recreates its own
 * network at its addresses (Compose does, as the network's settings changed) and its containers on
 * it and on the link only. The front door is then asked to reach it there.
 */
final readonly class SiteOverrides
{
    public function __construct(
        private SiteRegistry $registry,
        private SiteFiles $files,
        private ServerPaths $paths,
        private Images $images,
        private SiteLock $lock,
        private Compose $compose,
        private SiteLinks $links,
        private Gate $gate,
        private SiteNetworks $networks,
    ) {
    }

    /**
     * Each registered site's scope on the gate, as this release writes them, removing none: a site
     * whose project can't be made now (its folder missing, say) keeps its scope as it is, and is said.
     * The agent's start removes the scopes of sites that are gone (Gate::reconcile).
     *
     * @return array<string, string> Site => why its scope couldn't be written, or the gate refused it
     */
    private function scopes(): array
    {
        $sites = array_values(array_filter($this->registry->all(), static fn (Site $site): bool => Site::REMOVED !== $site->status));
        $projects = [];
        $failed = [];

        foreach ($sites as $site) {
            if (!is_dir($this->paths->site($site->name))) {
                // One whose add was cut short has no folder yet: nothing to write for it
                continue;
            }

            try {
                $projects[] = SiteProject::of($this->paths, $site->name, $site->domains, $site->project, $site->databaseMode, $this->networks->find($site->name));
            } catch (\Throwable $e) {
                $failed[$site->name->value] = $e->getMessage();
            }
        }

        foreach ($this->gate->apply($projects) as $project => $why) {
            $failed[$project] = $why;
        }

        return $failed;
    }

    /**
     * @return array<string, string> Site => what failed, for those that couldn't be brought up to date
     */
    public function refresh(): array
    {
        // Every site's scope first, as this release writes it (its format, its names on its link), so
        // the gate holds this release's before any site's containers are made again.
        $failed = $this->scopes();

        foreach ($this->registry->all() as $site) {
            $directory = $this->paths->site($site->name);

            // Not one being made or removed: their operations put them right, or back, themselves
            if (!\in_array($site->status, [Site::RUNNING, Site::FAILED], true) || !is_file($directory.'/'.SiteFiles::OVERRIDE)) {
                continue;
            }

            try {
                $this->lock->run($site->name, function () use ($site, $directory): void {
                    $override = $directory.'/'.SiteFiles::OVERRIDE;
                    $before = (string) file_get_contents($override);
                    // Its link and addresses: made the first time (a site of a release before D-34)
                    $subnets = $this->links->ensure($site->name, $site->project, Site::SHARED_DATABASE === $site->databaseMode);
                    $this->files->writeOverride($directory, $site->name, $site->domains, $site->databaseMode, $subnets, $site->project);
                    $image = SiteFiles::image($site->project);
                    $project = SiteProject::of($this->paths, $site->name, $site->domains, $site->project, $site->databaseMode, $subnets);

                    if (null === $this->images->id($project, $image)) {
                        $running = $this->images->ofContainer($project, $site->project.'-php-1');

                        if (null !== $running) {
                            $this->images->tag($project, $running, $image);
                        }
                    }

                    if (Site::RUNNING === $site->status && $before !== file_get_contents($override)) {
                        try {
                            $this->compose->up($project);
                        } catch (\Throwable $e) {
                            // As it was, matching the containers that run if they weren't changed; the
                            // next start tries again.
                            $this->files->write($override, $before, 0o644);

                            throw $e;
                        }

                        $domain = $site->domains[0]->value;

                        if (false === $this->links->frontDoorReaches($domain)) {
                            throw new \RuntimeException(\sprintf('It runs, but the front door doesn\'t reach it on its link (%s:80): the agent\'s rounds connect the front door again each minute, and say it if they can\'t ("quire-server logs agent").', SiteFiles::edgeName($domain)));
                        }
                    }
                });
            } catch (\Throwable $e) {
                $failed[$site->name->value] = $e->getMessage();
            }
        }

        return $failed;
    }
}
