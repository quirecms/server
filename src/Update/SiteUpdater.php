<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Docker\Compose;
use Quire\Server\Docker\Images;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Git\Git;
use Quire\Server\Git\Repository;
use Quire\Server\Operation\Operation;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\SiteUpdates;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Puts a new version of a site in place, and undoes the last one: an update (new packages, managed
 * sites) or a deploy (a commit of its repository, Git mode), the same way. The new version is built
 * while the site keeps running on its current image (a failed build changes nothing), the site is
 * backed up, the new version started (offline a few seconds; the front door says so to visitors) and
 * checked. If it fails, it's rolled back by itself: the files and the image it had, and its backup
 * restored if the new version's migrations ran.
 */
final readonly class SiteUpdater
{
    private const string PHP = 'php';

    /**
     * What a release that touched its site leaves until it's over (in the site's backups folder): how
     * to put the site back, for the agent's next start when a crash or a power cut stopped it.
     */
    private const string INTENT = 'release.json';

    public function __construct(
        private SiteRegistry $registry,
        private SiteUpdates $updates,
        private SiteGit $repositories,
        private OperationLog $operations,
        private SiteLock $lock,
        private SiteManager $sites,
        private Compose $compose,
        private Images $images,
        private Git $git,
        private ServerPaths $paths,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Makes sure a managed site's versions are recorded in its folder (its composer.lock), before
     * planning. (A site in Git mode has its repository's.).
     */
    public function prepare(SiteName $name): void
    {
        $site = $this->running($name);

        if (null === $this->repositories->of($name)) {
            $this->lock->run($name, fn () => $this->sites->pinComposerLock($name, $this->sites->projectOf($site)));
        }
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
     * Deploys a commit of a Git mode site's branch (its newest when null).
     *
     * @param \Closure(string): void $say
     *
     * @return string|null The commit deployed, or null when it runs it already
     *
     * @throws UpdateFailed When the deploy failed (rolled back, or not even that: see its message)
     */
    public function deploy(SiteName $name, ?string $commit, string $channel, string $requestedBy, \Closure $say): ?string
    {
        if (null !== $commit && !Repository::isCommit($commit)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a commit ID (all of its 40 characters).', mb_substr($commit, 0, 80)));
        }

        return $this->lock->run($name, function () use ($name, $commit, $channel, $requestedBy, $say): ?string {
            $site = $this->running($name);
            $git = $this->repositories->of($name) ?? throw new \DomainException(\sprintf('"%s" doesn\'t follow a Git repository: update it with "site:update".', $name));
            $repository = $git['repository'];
            $directory = $this->paths->site($name);
            $head = $this->git->head($repository, $name, $directory);
            // What runs is what was last deployed: a folder a deploy cut short left at its commit doesn't.
            $before = $git['deployed'] ?? $head;
            $target = $commit ?? $this->git->remoteHead($repository, $name);

            if ($target === $before && $head === $before) {
                $this->repositories->deployed($name, $target);

                return null;
            }

            $operation = $this->operations->start('site.deploy', $name, ['branch' => $repository->branch, 'from' => $before, 'to' => $target], $channel, $requestedBy);
            $step = $this->stepper($operation, $say);
            $operation->line(\sprintf('   %s: %s → %s', $repository->branch, substr($before, 0, 12), substr($target, 0, 12)));

            try {
                $released = $this->release(
                    $site,
                    $operation,
                    $step,
                    'deploy',
                    function () use ($step, $repository, $name, $directory, $target, $operation): void {
                        $step(\sprintf('Getting commit %s', substr($target, 0, 12)));
                        $this->git->checkout($repository, $name, $directory, $target, $operation->output(...));
                    },
                    fn () => $this->git->moveTo($repository, $name, $directory, $before),
                    ['commit' => $before, 'commit_after' => $target],
                );
            } catch (UpdateFailed $e) {
                // Not tried again by the agent: a new commit is.
                $this->repositories->failed($name, $target);

                throw $e;
            }

            $this->updates->record([
                ...$released,
                'id' => $this->uuid(),
                'site' => $name,
                'operation_id' => $operation->id,
                'kind' => SiteUpdates::DEPLOY,
                'quire_from' => null,
                'quire_to' => null,
                'packages' => [],
                'saved_files' => '',
                'skeleton_paths' => [],
                'commit_before' => $before,
                'commit_after' => $target,
            ]);
            $this->repositories->deployed($name, $target);
            $operation->succeed();

            return $target;
        });
    }

    /**
     * Builds the site again, its code as it is, on the runtime images its Dockerfile names (a newer
     * build of them, with its security fixes), and starts it as an update would: built beside, backed
     * up, checked, rolled back by itself.
     *
     * @param \Closure(string): void $say
     *
     * @throws UpdateFailed When it failed (rolled back, or not even that: see its message)
     */
    public function rebuild(SiteName $name, string $why, string $channel, string $requestedBy, \Closure $say): void
    {
        $this->lock->run($name, function () use ($name, $why, $channel, $requestedBy, $say): void {
            $site = $this->running($name);
            $operation = $this->operations->start('site.rebuild', $name, ['why' => $why], $channel, $requestedBy);
            $this->release($site, $operation, $this->stepper($operation, $say), 'rebuild', static function (): void {}, static function (): void {});
            $operation->succeed();
        });
    }

    /**
     * At the agent's start: every site whose release a crash or a power cut stopped after it was touched
     * (its intent is still there) is put back as it was before it, as a failed release is rolled back:
     * its files, the image it ran, its backup when one was taken (its new version's migrations may have
     * run), and checked. A deploy's commit isn't deployed again by itself. One that can't be put back is
     * marked failed.
     *
     * @param \Closure(string): void $say
     *
     * @return array<string, string> Site => what happened
     */
    public function recoverInterrupted(\Closure $say): array
    {
        $outcomes = [];

        foreach ($this->registry->all() as $site) {
            $file = $this->paths->backups($site->name).'/'.self::INTENT;

            if (Site::RUNNING !== $site->status || !is_file($file)) {
                continue;
            }

            try {
                $this->lock->run($site->name, function () use ($site, $file, $say): void {
                    $intent = json_decode((string) file_get_contents($file), true);

                    if (!\is_array($intent) || !\is_string($intent['image'] ?? null) || !\is_string($intent['image_before'] ?? null) || !\is_array($intent['put_back'] ?? null)) {
                        throw new \RuntimeException(\sprintf('%s can\'t be read: put the site back by hand.', $file));
                    }

                    $what = \is_string($intent['what'] ?? null) ? $intent['what'] : 'release';
                    $operation = $this->operations->start('site.recover', $site->name, ['interrupted' => \is_string($intent['operation'] ?? null) ? $intent['operation'] : '', 'what' => $what], 'agent', 'agent');
                    $step = $this->stepper($operation, $say);
                    $project = $this->sites->projectOf($site);
                    $directory = $this->paths->site($site->name);
                    $putBack = $intent['put_back'];

                    try {
                        $step(\sprintf('Putting "%s" back as it was before its %s, which was cut short', $site->name, $what));

                        if (\is_string($putBack['commit'] ?? null)) {
                            $git = $this->repositories->of($site->name) ?? throw new \DomainException('The site doesn\'t follow a Git repository any more.');
                            $this->git->moveTo($git['repository'], $site->name, $directory, $putBack['commit']);
                        } elseif (\is_string($putBack['files'] ?? null) && \is_array($putBack['paths'] ?? null) && is_dir($putBack['files'])) {
                            // Saved whole (or not at all): no saved copy means its files hadn't changed.
                            SkeletonFiles::restore($directory, array_values(array_filter($putBack['paths'], \is_string(...))), $putBack['files']);
                        }

                        $this->images->tag($intent['image_before'], $intent['image']);
                        $this->compose->up($project, $operation->output(...));

                        if (\is_string($intent['backup'] ?? null)) {
                            $step('Restoring its backup (the new version\'s migrations may have run)');
                            $this->restore($site, $project, $intent['backup'], $operation);
                        }

                        $step('Checking it');
                        $this->check($project, $site);
                    } catch (\Throwable $e) {
                        $this->registry->setStatus($site->name, Site::FAILED);
                        $operation->fail(\sprintf('Putting it back failed: %s. Its release record: %s.', $e->getMessage(), $file));

                        throw $e;
                    }

                    if (\is_string($putBack['commit'] ?? null) && \is_string($putBack['commit_after'] ?? null)) {
                        $this->repositories->deployed($site->name, $putBack['commit']);
                        $this->repositories->failed($site->name, $putBack['commit_after']);
                    }

                    $this->filesystem->remove($file);
                    $operation->succeed();
                });
                $outcomes[$site->name->value] = 'put back as it was before';
            } catch (\Throwable $e) {
                $outcomes[$site->name->value] = $e->getMessage();
            }
        }

        return $outcomes;
    }

    /**
     * Restores a backup of the site (e.g. one from off the server): its database and files as the
     * backup has them. The site is backed up as it is first; if it doesn't answer after the restore,
     * that backup is put back.
     *
     * @param array<string, scalar>  $parameters What's recorded of where it came from
     * @param \Closure(string): void $say
     *
     * @throws UpdateFailed When it failed (the site as it was, or not even that: see its message)
     */
    public function restoreBackup(SiteName $name, string $backup, array $parameters, string $channel, string $requestedBy, \Closure $say): void
    {
        $this->lock->run($name, function () use ($name, $backup, $parameters, $channel, $requestedBy, $say): void {
            $site = $this->running($name);
            $project = $this->sites->projectOf($site);
            $operation = $this->operations->start('site.restore', $name, $parameters, $channel, $requestedBy);
            $step = $this->stepper($operation, $say);
            $current = null;

            try {
                $step('Backing it up, as it is');
                $current = $this->sites->backup($name, $project, 'before-restore');
                $operation->line('Backed up to '.$current);

                $step('Restoring the backup');
                $this->restore($site, $project, $backup, $operation);

                $step('Checking it');
                $this->check($project, $site);
            } catch (\Throwable $e) {
                $operation->line('!! '.$e->getMessage());

                if (null === $current) {
                    $operation->fail('Restoring it failed before anything changed: '.$e->getMessage());

                    throw new UpdateFailed($operation->id, true, $e->getMessage(), $e);
                }

                $step('Putting it back as it was before the restore');

                try {
                    $this->restore($site, $project, $current, $operation);
                    $this->check($project, $site);
                } catch (\Throwable $failure) {
                    $this->registry->setStatus($name, Site::FAILED);
                    $operation->fail(\sprintf('Restoring the backup failed (%s), and putting the site back failed too (%s). Its backup from before: %s.', $e->getMessage(), $failure->getMessage(), $current));

                    throw new UpdateFailed($operation->id, false, $e->getMessage(), $e);
                }

                $operation->fail('Restoring the backup failed, and the site is as it was before: '.$e->getMessage());

                throw new UpdateFailed($operation->id, true, $e->getMessage(), $e);
            }

            $operation->succeed();
        });
    }

    /**
     * Undoes the site's last update or deploy (within a week): its files and image before it, and,
     * when its migrations ran (or it isn't known), its database and files as they were then.
     *
     * @param bool                   $loseChanges Whether the site's changes since may be lost (when its backup must be restored)
     * @param \Closure(string): void $say
     */
    public function undo(SiteName $name, bool $loseChanges, string $channel, string $requestedBy, \Closure $say): void
    {
        $this->lock->run($name, function () use ($name, $loseChanges, $channel, $requestedBy, $say): void {
            $site = $this->running($name);
            $update = $this->updates->undoable($name) ?? throw new \DomainException('The site has nothing to undo (only its last update or deploy can be undone, for a week).');
            $what = SiteUpdates::DEPLOY === $update['kind'] ? 'deploy' : 'update';
            $project = $this->sites->projectOf($site);
            $image = $this->compose->image($project, self::PHP);

            if ($this->images->id($image) !== $update['image_after']) {
                throw new \DomainException(\sprintf('The site was built again since its last %s: it can\'t be undone.', $what));
            }

            $restore = false !== $update['migrations_ran'];

            if ($restore && !$loseChanges) {
                throw new \DomainException(\sprintf('Undoing it restores the site\'s backup from before the %s (%s): its changes since are lost.', $what, $update['created_at']));
            }

            [$back, $forward] = $this->undoFiles($name, $update);
            $operation = $this->operations->start('site.undo', $name, ['undoes' => $update['id'], 'kind' => $update['kind'], 'restore' => $restore], $channel, $requestedBy);
            $step = $this->stepper($operation, $say);
            $current = null;

            try {
                $step('Backing it up, as it is');
                $current = $this->sites->backup($name, $project, 'before-undo');
                $operation->line('Backed up to '.$current);

                $step(\sprintf('Putting back its files and image from before the %s', $what));
                $back();
                $this->images->tag($update['image_before'], $image);
                $this->compose->up($project, $operation->output(...));

                if ($restore) {
                    $step(\sprintf('Restoring its backup from before the %s', $what));
                    $this->restore($site, $project, $update['backup'], $operation);
                }

                $step('Checking it');
                $this->check($project, $site);
            } catch (\Throwable $e) {
                $operation->line('!! '.$e->getMessage());
                $step('Putting it back as it was before the undo');

                try {
                    $forward();
                    $this->images->tag($update['image_after'], $image);
                    $this->compose->up($project, $operation->output(...));

                    if ($restore && null !== $current) {
                        $this->restore($site, $project, $current, $operation);
                    }

                    $this->check($project, $site);
                } catch (\Throwable $failure) {
                    $this->registry->setStatus($name, Site::FAILED);
                    $operation->fail(\sprintf('Undoing the %s failed (%s), and putting the site back failed too (%s). Its backups: %s, %s.', $what, $e->getMessage(), $failure->getMessage(), $update['backup'], $current ?? '-'));

                    throw new UpdateFailed($operation->id, false, $e->getMessage(), $e);
                }

                $operation->fail(\sprintf('Undoing the %s failed, and the site is as it was before: %s', $what, $e->getMessage()));

                throw new UpdateFailed($operation->id, true, $e->getMessage(), $e);
            }

            $this->updates->markUndone($update['id']);

            if (SiteUpdates::DEPLOY === $update['kind'] && null !== $update['commit_before'] && null !== $update['commit_after']) {
                // It runs the commit before; the undone one isn't deployed again by the agent.
                $this->repositories->deployed($name, $update['commit_before']);
                $this->repositories->failed($name, $update['commit_after']);
            }

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

        if (null !== $this->repositories->of($name)) {
            throw new \DomainException(\sprintf('"%s" follows a Git repository: its updates are made there (composer update, committed), then deployed with "site:deploy".', $name));
        }

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
        $saved = $this->paths->backups($name).'/update-'.date('Ymd-His').'/files';
        $skeletonPaths = array_column($plan->appliedSkeletonFiles(), 'path');
        $paths = ['composer.json', 'composer.lock', ...$skeletonPaths];

        foreach ($plan->packages as $change) {
            $operation->line(\sprintf('   %s: %s → %s', $change['package'], $change['from'] ?? '(new)', $change['to'] ?? '(removed)'));
        }

        $released = $this->release(
            $site,
            $operation,
            $step,
            'update',
            function () use ($step, $saved, $directory, $paths, $plan): void {
                $step('Keeping its files as they are, to put them back');
                // Whole or not at all: a saved copy that's there is a complete one.
                $this->filesystem->mkdir($saved.'.partial', 0o700);
                SkeletonFiles::save($directory, $paths, $saved.'.partial');
                $this->filesystem->rename($saved.'.partial', $saved);

                $step('Changing its files');
                $this->filesystem->dumpFile($directory.'/composer.json', $plan->composerJson);
                $this->filesystem->dumpFile($directory.'/composer.lock', $plan->to->content);

                if (null !== $plan->skeletonTarget) {
                    SkeletonFiles::apply($directory, $plan->skeletonTarget, $plan->appliedSkeletonFiles());
                }
            },
            static fn () => SkeletonFiles::restore($directory, $paths, $saved),
            ['files' => $saved, 'paths' => $paths],
        );

        $this->updates->record([
            ...$released,
            'id' => $this->uuid(),
            'site' => $name,
            'operation_id' => $operation->id,
            'kind' => SiteUpdates::UPDATE,
            'quire_from' => $plan->quireFrom(),
            'quire_to' => $plan->quireTo(),
            'packages' => $plan->packages,
            'saved_files' => $saved,
            'skeleton_paths' => $skeletonPaths,
            'commit_before' => null,
            'commit_after' => null,
        ]);
        $operation->succeed();
    }

    /**
     * Builds, backs up, swaps and checks a new version of the site, whose files $change puts in place
     * (and $revert puts back as they were): rolled back by itself if anything fails after the swap.
     *
     * @param \Closure(string): void                                                              $step
     * @param \Closure(): void                                                                    $change
     * @param \Closure(): void                                                                    $revert
     * @param array{commit?: string, commit_after?: string, files?: string, paths?: list<string>} $putBack How its files are put back after a crash: the commit before, or the saved copies
     *
     * @return array{image_before: string, image_after: string, backup: string, migrations_ran: ?bool}
     */
    private function release(Site $site, Operation $operation, \Closure $step, string $what, \Closure $change, \Closure $revert, array $putBack = []): array
    {
        $project = $this->sites->projectOf($site);
        $image = $this->compose->image($project, self::PHP);
        // The image it runs: its tag may name another (a build a crash cut short, never run).
        $before = $this->images->ofContainer($project->name().'-'.self::PHP.'-1') ?? $this->images->id($image);
        $changed = false;
        $intent = ['operation' => $operation->id, 'what' => $what, 'image' => $image, 'image_before' => $before, 'backup' => null, 'put_back' => $putBack];

        try {
            if (null === $before) {
                throw new \RuntimeException(\sprintf('The site\'s image "%s" isn\'t there: it can\'t be rolled back to.', $image));
            }

            // Before its files change: a crash while it builds leaves them put back too.
            $this->writeIntent($site->name, $intent);
            $changed = true;
            $change();

            $step('Checking and building the new version (a few minutes; it keeps running meanwhile)');
            $this->compose->build($project, $operation->output(...));
        } catch (\Throwable $e) {
            if ($changed) {
                $revert();
            }

            // Back to the image it runs, whatever the build left under its name.
            if (null !== $before) {
                $this->images->tag($before, $image);
            }

            $this->filesystem->remove($this->paths->backups($site->name).'/'.self::INTENT);
            $operation->fail(\sprintf('The %s was stopped before the site was touched: %s', $what, $e->getMessage()));

            throw new UpdateFailed($operation->id, true, $e->getMessage(), $e);
        }

        $after = $this->images->id($image) ?? throw new \LogicException('The new image was just built.');
        // A name for the image it ran, so nothing prunes it while this can be undone.
        $this->images->tag($before, $this->withoutTag($image).':before-update');
        $backup = null;
        $migrationsBefore = null;
        $migrationsAfter = null;
        try {
            try {
                $step('Backing it up');
                $backup = $this->sites->backup($site->name, $project, $what);
                $operation->line('Backed up to '.$backup);
                $this->writeIntent($site->name, ['backup' => $backup] + $intent);
                $migrationsBefore = $this->executedMigrations($project);

                $step('Starting the new version (a few seconds offline)');
                $this->compose->up($project, $operation->output(...));

                $step('Checking it');
                $this->check($project, $site);
                $migrationsAfter = $this->executedMigrations($project);
            } catch (\Throwable $e) {
                $operation->line('!! '.$e->getMessage());
                $step('Rolling it back');
                $this->rollBack($project, $site, $what, $image, $before, $revert, $backup, $migrationsBefore, $operation, $e);
            }
        } finally {
            // Over: rolled back, or not even that (the site is marked failed, with what to do).
            $this->filesystem->remove($this->paths->backups($site->name).'/'.self::INTENT);
        }

        return [
            'image_before' => $before,
            'image_after' => $after,
            'backup' => $backup,
            'migrations_ran' => null === $migrationsBefore || null === $migrationsAfter ? null : $migrationsAfter !== $migrationsBefore,
        ];
    }

    /**
     * It failed once started: the files and the image it had, and its backup when migrations may have
     * changed its database. Always throws.
     *
     * @param \Closure(): void $revert
     */
    private function rollBack(SiteProject $project, Site $site, string $what, string $image, string $before, \Closure $revert, ?string $backup, ?int $migrationsBefore, Operation $operation, \Throwable $cause): never
    {
        try {
            // Whether the new version's migrations ran, asked of it while it may still answer.
            $migrationsNow = null === $migrationsBefore ? null : $this->executedMigrations($project);
            $revert();
            $this->images->tag($before, $image);
            $this->compose->up($project, $operation->output(...));

            if (null !== $backup && (null === $migrationsNow || $migrationsNow !== $migrationsBefore)) {
                $operation->line('== Restoring its backup (the new version\'s migrations may have run)');
                $this->restore($site, $project, $backup, $operation);
            }

            $this->check($project, $site);
        } catch (\Throwable $failure) {
            $this->registry->setStatus($site->name, Site::FAILED);
            $operation->fail(\sprintf('The %s failed (%s), and rolling it back failed too (%s). Its backup: %s.', $what, $cause->getMessage(), $failure->getMessage(), $backup ?? '-'));

            throw new UpdateFailed($operation->id, false, $cause->getMessage(), $cause);
        }

        $operation->fail(\sprintf('The %s failed and was rolled back: %s', $what, $cause->getMessage()));

        throw new UpdateFailed($operation->id, true, $cause->getMessage(), $cause);
    }

    /**
     * How to put an update's or a deploy's files back, and forward again (if undoing it fails).
     *
     * @param array{kind: string, saved_files: string, skeleton_paths: list<string>, commit_before: ?string, commit_after: ?string} $update
     *
     * @return array{\Closure(): void, \Closure(): void}
     */
    private function undoFiles(SiteName $name, array $update): array
    {
        $directory = $this->paths->site($name);

        if (SiteUpdates::DEPLOY === $update['kind']) {
            $git = $this->repositories->of($name) ?? throw new \DomainException('The site doesn\'t follow a Git repository any more.');
            $before = $update['commit_before'] ?? throw new \LogicException('A deploy has its commits.');
            $after = $update['commit_after'] ?? throw new \LogicException('A deploy has its commits.');

            return [
                fn () => $this->git->moveTo($git['repository'], $name, $directory, $before),
                fn () => $this->git->moveTo($git['repository'], $name, $directory, $after),
            ];
        }

        $paths = ['composer.json', 'composer.lock', ...$update['skeleton_paths']];
        $now = $this->paths->backups($name).'/undo-'.date('Ymd-His').'/files';
        $this->filesystem->mkdir($now, 0o700);
        SkeletonFiles::save($directory, $paths, $now);

        return [
            static fn () => SkeletonFiles::restore($directory, $paths, $update['saved_files']),
            static fn () => SkeletonFiles::restore($directory, $paths, $now),
        ];
    }

    /**
     * The backup streamed into quire:restore: no copy of it lands in the site's container (which runs
     * as another user than the backup's owner, and keeps nothing it doesn't need).
     */
    private function restore(Site $site, SiteProject $project, string $backup, Operation $operation): void
    {
        $this->compose->exec($project, self::PHP, ['bin/console', 'quire:restore', '-', '--force', '--no-interaction'], $operation->output(...), 3600, $backup)->orFail('Restoring the backup');
        $this->sites->restoreDatabase($site->name, $project, $backup, $operation->output(...));
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

    /**
     * Written whole and to disk before the site is touched: what a power cut leaves is this or nothing.
     *
     * @param array<string, mixed> $intent
     */
    private function writeIntent(SiteName $name, array $intent): void
    {
        $file = $this->paths->backups($name).'/'.self::INTENT;
        $this->filesystem->mkdir(\dirname($file), 0o700);
        $temporary = $file.'.new';
        $handle = fopen($temporary, 'w');

        if (false === $handle) {
            throw new \RuntimeException(\sprintf('%s can\'t be written.', $temporary));
        }

        try {
            fwrite($handle, json_encode($intent, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
            fflush($handle);
            fsync($handle);
        } finally {
            fclose($handle);
        }

        chmod($temporary, 0o600);
        rename($temporary, $file);
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
