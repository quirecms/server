<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Docker\Compose;
use Quire\Server\Docker\Images;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Operation\Operation;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\SiteUpdates;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Updates a site, and undoes its last update. An update is built while the site keeps running on its
 * current image (a failed build changes nothing), backed up, started (offline a few seconds; the front
 * door says so to visitors), and checked. If it fails, it's rolled back by itself: the files and the
 * image it had, and its backup restored if the update's migrations ran.
 */
final readonly class SiteUpdater
{
    private const string PHP = 'php';

    public function __construct(
        private SiteRegistry $registry,
        private SiteUpdates $updates,
        private OperationLog $operations,
        private SiteLock $lock,
        private SiteManager $sites,
        private Compose $compose,
        private Images $images,
        private ServerPaths $paths,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Makes sure the site's versions are recorded in its folder (its composer.lock), before planning.
     */
    public function prepare(SiteName $name): void
    {
        $site = $this->running($name);
        $this->lock->run($name, fn () => $this->sites->pinComposerLock($name, $this->sites->projectOf($site)));
    }

    /**
     * Applies a plan (see UpdatePlanner), and discards it.
     *
     * @param bool                   $keepEdited Leave the skeleton's files the site edited as they are (conflicts); refused otherwise
     * @param \Closure(string): void $say
     *
     * @throws UpdateFailed When the update failed (rolled back, or not even that: see its message)
     */
    public function update(UpdatePlan $plan, bool $keepEdited, string $channel, string $requestedBy, \Closure $say): void
    {
        try {
            $this->lock->run($plan->site, function () use ($plan, $keepEdited, $channel, $requestedBy, $say): void {
                $this->apply($plan, $keepEdited, $channel, $requestedBy, $say);
            });
        } finally {
            $plan->discard();
        }
    }

    /**
     * Undoes the site's last update (within a week): its files and image before it, and, when the
     * update's migrations ran (or it isn't known), its database and files as they were then.
     *
     * @param bool                   $loseChanges Whether the site's changes since the update may be lost (when its backup must be restored)
     * @param \Closure(string): void $say
     */
    public function undo(SiteName $name, bool $loseChanges, string $channel, string $requestedBy, \Closure $say): void
    {
        $this->lock->run($name, function () use ($name, $loseChanges, $channel, $requestedBy, $say): void {
            $site = $this->running($name);
            $update = $this->updates->undoable($name) ?? throw new \DomainException('The site has no update to undo (only its last one can be undone, for a week).');
            $project = $this->sites->projectOf($site);
            $image = $this->compose->image($project, self::PHP);

            if ($this->images->id($image) !== $update['image_after']) {
                throw new \DomainException('The site was built again since its last update: it can\'t be undone.');
            }

            $restore = false !== $update['migrations_ran'];

            if ($restore && !$loseChanges) {
                throw new \DomainException(\sprintf('Undoing it restores the site\'s backup from before the update (%s): its changes since are lost.', $update['created_at']));
            }

            $operation = $this->operations->start('site.undo-update', $name, ['update' => $update['id'], 'restore' => $restore], $channel, $requestedBy);
            $step = $this->stepper($operation, $say);
            $directory = $this->paths->site($name);
            $now = $this->paths->backups($name).'/undo-'.date('Ymd-His').'/files';
            $paths = ['composer.json', 'composer.lock', ...$update['skeleton_paths']];
            $current = null;

            try {
                $step('Backing it up, as it is');
                $current = $this->sites->backup($name, $project, 'before-undo');
                $operation->line('Backed up to '.$current);
                $this->filesystem->mkdir($now, 0o700);
                SkeletonFiles::save($directory, $paths, $now);

                $step('Putting back its files and image from before the update');
                SkeletonFiles::restore($directory, $paths, $update['saved_files']);
                $this->images->tag($update['image_before'], $image);
                $this->compose->up($project, $operation->output(...));

                if ($restore) {
                    $step('Restoring its backup from before the update');
                    $this->restore($project, $update['backup'], $operation);
                }

                $step('Checking it');
                $this->check($project, $site);
            } catch (\Throwable $e) {
                $operation->line('!! '.$e->getMessage());
                $step('Putting it back as it was before the undo');

                try {
                    SkeletonFiles::restore($directory, $paths, $now);
                    $this->images->tag($update['image_after'], $image);
                    $this->compose->up($project, $operation->output(...));

                    if ($restore && null !== $current) {
                        $this->restore($project, $current, $operation);
                    }

                    $this->check($project, $site);
                } catch (\Throwable $failure) {
                    $this->registry->setStatus($name, Site::FAILED);
                    $operation->fail(\sprintf('Undoing the update failed (%s), and putting the site back failed too (%s). Its backups: %s, %s.', $e->getMessage(), $failure->getMessage(), $update['backup'], $current ?? '-'));

                    throw new UpdateFailed($operation->id, false, $e->getMessage(), $e);
                }

                $operation->fail('Undoing the update failed, and the site is as it was before: '.$e->getMessage());

                throw new UpdateFailed($operation->id, true, $e->getMessage(), $e);
            }

            $this->updates->markUndone($update['id']);
            $operation->succeed();
        });
    }

    /**
     * @param \Closure(string): void $say
     */
    private function apply(UpdatePlan $plan, bool $keepEdited, string $channel, string $requestedBy, \Closure $say): void
    {
        $name = $plan->site;
        $site = $this->running($name);
        $directory = $this->paths->site($name);

        if (UpdatePlanner::siteFiles($directory) !== $plan->siteFiles) {
            throw new \DomainException('The site\'s composer.json or composer.lock changed since the update was planned: plan it again.');
        }

        if ([] !== $plan->conflicts() && !$keepEdited) {
            throw new \DomainException(\sprintf('The site edited files the new version changes too: %s. Merge them by hand, or keep the site\'s own (--keep-edited-files).', implode(', ', array_column($plan->conflicts(), 'path'))));
        }

        if (!$plan->changesAnything()) {
            throw new \DomainException('The site is up to date.');
        }

        $operation = $this->operations->start('site.update', $name, ['from' => $plan->quireFrom(), 'to' => $plan->quireTo(), 'packages' => \count($plan->packages)], $channel, $requestedBy);
        $step = $this->stepper($operation, $say);
        $project = $this->sites->projectOf($site);
        $image = $this->compose->image($project, self::PHP);
        $before = $this->images->id($image);
        $saved = $this->paths->backups($name).'/update-'.date('Ymd-His').'/files';
        $skeletonPaths = array_column($plan->appliedSkeletonFiles(), 'path');
        $paths = ['composer.json', 'composer.lock', ...$skeletonPaths];

        foreach ($plan->packages as $change) {
            $operation->line(\sprintf('   %s: %s → %s', $change['package'], $change['from'] ?? '(new)', $change['to'] ?? '(removed)'));
        }

        try {
            if (null === $before) {
                throw new \RuntimeException(\sprintf('The site\'s image "%s" isn\'t there: it can\'t be rolled back to.', $image));
            }

            $step('Keeping its files as they are, to put them back');
            $this->filesystem->mkdir($saved, 0o700);
            SkeletonFiles::save($directory, $paths, $saved);

            $step('Changing its files');
            $this->filesystem->dumpFile($directory.'/composer.json', $plan->composerJson);
            $this->filesystem->dumpFile($directory.'/composer.lock', $plan->to->content);

            if (null !== $plan->skeletonTarget) {
                SkeletonFiles::apply($directory, $plan->skeletonTarget, $plan->appliedSkeletonFiles());
            }

            $step('Checking and building the new version (a few minutes; it keeps running meanwhile)');
            $this->compose->build($project, $operation->output(...));
        } catch (\Throwable $e) {
            SkeletonFiles::restore($directory, $paths, $saved);

            // Back to the image it runs, whatever the build left under its name.
            if (null !== $before) {
                $this->images->tag($before, $image);
            }

            $operation->fail('The update was stopped before the site was touched: '.$e->getMessage());

            throw new UpdateFailed($operation->id, true, $e->getMessage(), $e);
        }

        $after = $this->images->id($image) ?? throw new \LogicException('The new image was just built.');
        // A name for the image it ran, so nothing prunes it while the update can be undone.
        $this->images->tag($before, $this->withoutTag($image).':before-update');
        $backup = null;
        $migrationsBefore = null;
        $migrationsAfter = null;

        try {
            $step('Backing it up');
            $backup = $this->sites->backup($name, $project, 'update');
            $operation->line('Backed up to '.$backup);
            $migrationsBefore = $this->executedMigrations($project);

            $step('Starting the new version (a few seconds offline)');
            $this->compose->up($project, $operation->output(...));

            $step('Checking it');
            $this->check($project, $site);
            $migrationsAfter = $this->executedMigrations($project);
        } catch (\Throwable $e) {
            $operation->line('!! '.$e->getMessage());
            $step('Rolling it back');
            $this->rollBack($project, $site, $image, $before, $saved, $paths, $backup, $migrationsBefore, $operation, $e);
        }

        $this->updates->record([
            'id' => $this->uuid(),
            'site' => $name,
            'operation_id' => $operation->id,
            'quire_from' => $plan->quireFrom(),
            'quire_to' => $plan->quireTo(),
            'packages' => $plan->packages,
            'image_before' => $before,
            'image_after' => $after,
            'saved_files' => $saved,
            'skeleton_paths' => $skeletonPaths,
            'backup' => $backup,
            'migrations_ran' => null === $migrationsBefore || null === $migrationsAfter ? null : $migrationsAfter !== $migrationsBefore,
        ]);
        $operation->succeed();
    }

    /**
     * The update failed once started: the files and the image it had, and its backup when migrations
     * may have changed its database. Always throws.
     *
     * @param list<string> $paths
     */
    private function rollBack(SiteProject $project, Site $site, string $image, string $before, string $saved, array $paths, ?string $backup, ?int $migrationsBefore, Operation $operation, \Throwable $cause): never
    {
        try {
            // Whether the new version's migrations ran, asked of it while it may still answer.
            $migrationsNow = null === $migrationsBefore ? null : $this->executedMigrations($project);
            SkeletonFiles::restore($this->paths->site($site->name), $paths, $saved);
            $this->images->tag($before, $image);
            $this->compose->up($project, $operation->output(...));

            if (null !== $backup && (null === $migrationsNow || $migrationsNow !== $migrationsBefore)) {
                $operation->line('== Restoring its backup (the new version\'s migrations may have run)');
                $this->restore($project, $backup, $operation);
            }

            $this->check($project, $site);
        } catch (\Throwable $failure) {
            $this->registry->setStatus($site->name, Site::FAILED);
            $operation->fail(\sprintf('The update failed (%s), and rolling it back failed too (%s). Its backup: %s.', $cause->getMessage(), $failure->getMessage(), $backup ?? '-'));

            throw new UpdateFailed($operation->id, false, $cause->getMessage(), $cause);
        }

        $operation->fail('The update failed and was rolled back: '.$cause->getMessage());

        throw new UpdateFailed($operation->id, true, $cause->getMessage(), $cause);
    }

    /**
     * The backup streamed into quire:restore: no copy of it lands in the site's container (which runs
     * as another user than the backup's owner, and keeps nothing it doesn't need).
     */
    private function restore(SiteProject $project, string $backup, Operation $operation): void
    {
        $this->compose->exec($project, self::PHP, ['bin/console', 'quire:restore', '-', '--force', '--no-interaction'], $operation->output(...), 3600, $backup)->orFail('Restoring the backup');
    }

    /**
     * The site answers: its health check, its home page and its sign-in page, from inside its container
     * (as the front door asks: for its main domain, over HTTPS), for up to a minute.
     */
    private function check(SiteProject $project, Site $site): void
    {
        $code = <<<'PHP'
            $host = %s;
            $deadline = time() + 60;
            do {
                $failed = [];
                foreach (['/_quire/health' => true, '/' => false, '/login' => false] as $path => $exactlyOk) {
                    $context = stream_context_create(['http' => ['header' => "Host: $host\r\nX-Forwarded-Proto: https\r\n", 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
                    @file_get_contents('http://127.0.0.1:80'.$path, false, $context);
                    $status = preg_match('#^HTTP/\S+ (\d{3})#', (http_get_last_response_headers() ?? [''])[0] ?? '', $m) ? (int) $m[1] : 0;
                    if ($exactlyOk ? 200 !== $status : (0 === $status || $status >= 500)) {
                        $failed[] = "$path: ".($status ?: 'no answer');
                    }
                }
                if ([] === $failed) {
                    exit(0);
                }
                sleep(5);
            } while (time() < $deadline);
            fwrite(STDERR, implode(', ', $failed)."\n");
            exit(1);
            PHP;

        $this->compose->exec($project, self::PHP, ['php', '-r', \sprintf($code, var_export($site->domains[0]->value, true))], timeout: 120)
            ->orFail('Checking the site (health, home page, sign-in page)');
    }

    /**
     * How many migrations its database has run, from quire:about (sites keeping the server contract);
     * null when it isn't known.
     */
    private function executedMigrations(SiteProject $project): ?int
    {
        try {
            $result = $this->compose->exec($project, self::PHP, ['bin/console', 'quire:about', '--format=json'], timeout: 120);
            $about = $result->successful() ? json_decode($result->output, true) : null;
            $executed = \is_array($about) && \is_array($about['migrations'] ?? null) ? ($about['migrations']['executed'] ?? null) : null;

            return \is_int($executed) ? $executed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function running(SiteName $name): Site
    {
        $site = $this->registry->get($name);

        if (!$site instanceof Site || Site::RUNNING !== $site->status) {
            throw new \DomainException(\sprintf('There\'s no running site named "%s".', $name));
        }

        return $site;
    }

    /**
     * @param \Closure(string): void $say
     *
     * @return \Closure(string): void
     */
    private function stepper(Operation $operation, \Closure $say): \Closure
    {
        return static function (string $what) use ($operation, $say): void {
            $operation->line('== '.$what);
            $say($what);
        };
    }

    private function withoutTag(string $image): string
    {
        $slash = strrpos($image, '/');
        $colon = strrpos($image, ':');

        return false !== $colon && (false === $slash || $colon > $slash) ? substr($image, 0, $colon) : $image;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
