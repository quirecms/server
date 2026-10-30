<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

use Quire\Server\Docker\Compose;
use Quire\Server\Docker\Images;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;

/**
 * Every site's compose.server.yaml as this release writes it, at each start of the agent: a release
 * may harden sites further, or name things anew (their images are named "quire-sites/<project>/…" since
 * 0.3.1). The image a site runs gets its name too when it has none yet, so its next update can still be
 * undone. A site whose override changed is started again with it (a few seconds), once.
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
    ) {
    }

    /**
     * @return array<string, string> Site => what failed, for those that couldn't be brought up to date
     */
    public function refresh(): array
    {
        $failed = [];

        foreach ($this->registry->all() as $site) {
            $directory = $this->paths->site($site->name);

            if (Site::RUNNING !== $site->status || !is_file($directory.'/'.SiteFiles::OVERRIDE)) {
                continue;
            }

            try {
                $this->lock->run($site->name, function () use ($site, $directory): void {
                    $override = $directory.'/'.SiteFiles::OVERRIDE;
                    $before = (string) file_get_contents($override);
                    $this->files->writeOverride($directory, $site->name, $site->domains, $site->databaseMode, $site->project);
                    $image = SiteFiles::image($site->project);

                    if (null === $this->images->id($image)) {
                        $running = $this->images->ofContainer($site->project.'-php-1');

                        if (null !== $running) {
                            $this->images->tag($running, $image);
                        }
                    }

                    if ($before !== file_get_contents($override)) {
                        try {
                            $this->compose->up(SiteProject::of($this->paths, $site->name, $site->domains, $site->project, $site->databaseMode));
                        } catch (\Throwable $e) {
                            // As it was, matching the containers that run: they weren't changed.
                            $this->files->write($override, $before, 0o644);

                            throw $e;
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
