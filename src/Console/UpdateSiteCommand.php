<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Site\SiteName;
use Quire\Server\Update\SiteUpdater;
use Quire\Server\Update\SkeletonFiles;
use Quire\Server\Update\UpdateFailed;
use Quire\Server\Update\UpdatePlan;
use Quire\Server\Update\UpdatePlanner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:update',
    description: 'Update a site: its Quire, extensions and other packages, and the files it got from the skeleton',
    help: <<<'HELP'
        Shows what would change first (packages, known vulnerabilities fixed, the skeleton's files), then,
        once you agree: builds the new version while the site keeps running, backs the site up, starts
        the new version (a few seconds offline; the front door tells visitors) and checks it. If it
        fails, it's rolled back by itself. Undo it within a week with "site:undo-update".

          <info>quire-server site:update jane</info>              newest versions its composer.json allows
          <info>quire-server site:update jane --to=0.2.0</info>   another Quire release (e.g. a new minor version)
          <info>quire-server site:update jane --dry-run</info>    only show what would change

        Files the site got from the skeleton (Dockerfile, Compose files, FrankenPHP's) follow the new
        version, unless the site edited them and the new version changes them too: then the update
        stops, to merge them by hand, or keep the site's own with --keep-edited-files.
        HELP,
)]
final readonly class UpdateSiteCommand
{
    public function __construct(
        private UpdatePlanner $planner,
        private SiteUpdater $updater,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name, e.g. "jane"')]
        string $name,
        #[Option(description: 'A Quire release to move to, e.g. "0.2.0"')]
        ?string $to = null,
        #[Option(description: 'Only show what would change')]
        bool $dryRun = false,
        #[Option(description: 'Keep the skeleton\'s files the site edited, though the new version changes them')]
        bool $keepEditedFiles = false,
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
    ): int {
        try {
            $site = new SiteName($name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->title(\sprintf('Updating the site "%s"', $site));

        try {
            $io->writeln('  Working out what would change…');
            $this->updater->prepare($site);
            $plan = $this->planner->plan($site, $to);
        } catch (\Throwable $e) {
            $io->error(['Working out the update failed (nothing changed):', $e->getMessage()]);

            return Command::FAILURE;
        }

        $this->show($io, $plan);

        if (!$plan->changesAnything()) {
            $plan->discard();
            $io->success('The site is up to date.');

            return Command::SUCCESS;
        }

        if ([] !== $plan->conflicts() && !$keepEditedFiles) {
            $plan->discard();
            $io->error('The site edited files the new version changes too (above): merge them by hand from the new skeleton, or keep the site\'s own with --keep-edited-files.');

            return Command::FAILURE;
        }

        if ($dryRun || (!$yes && !$io->confirm('Update the site?', false))) {
            $plan->discard();
            $io->writeln('Nothing changed.');

            return Command::SUCCESS;
        }

        try {
            $this->updater->update($plan, $keepEditedFiles, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (UpdateFailed $e) {
            $io->error($e->restored
                ? ['The update failed, and the site is as it was before:', $e->getMessage(), 'Its log: quire-server operations '.$e->operationId]
                : ['The update failed, and so did putting the site back: it needs you.', $e->getMessage(), 'Its log (and where its backup is): quire-server operations '.$e->operationId]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error(['The site wasn\'t updated:', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success(null !== $plan->quireTo() && $plan->quireFrom() !== $plan->quireTo()
            ? \sprintf('"%s" runs Quire %s.', $site, $plan->quireTo())
            : \sprintf('"%s" is up to date.', $site));
        $io->writeln(\sprintf('Undo it within a week: quire-server site:undo-update %s', $site));

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, UpdatePlan $plan): void
    {
        if (null !== $plan->quireTo() && $plan->quireFrom() !== $plan->quireTo()) {
            $io->section(\sprintf('Quire %s → %s', $plan->quireFrom() ?? '?', $plan->quireTo()));
        }

        if ([] !== $plan->packages) {
            $io->table(['Package', 'From', 'To'], array_map(static fn (array $change): array => [$change['package'], $change['from'] ?? '(new)', $change['to'] ?? '(removed)'], $plan->packages));
        }

        foreach ($plan->fixedAdvisories() as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $io->writeln(\sprintf('  <info>Fixes</info> %s: %s%s', $package, $advisory['title'], null === $advisory['cve'] ? '' : ' ('.$advisory['cve'].')'));
            }
        }

        foreach ($plan->advisoriesAfter as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $io->writeln(\sprintf('  <comment>Still affected</comment> %s: %s%s', $package, $advisory['title'], null === $advisory['link'] ? '' : ' '.$advisory['link']));
            }
        }

        if ([] !== $plan->skeletonFiles) {
            $io->section('Files from the skeleton');
            $io->listing(array_map(static fn (array $change): string => \sprintf('%s: %s', $change['path'], match ($change['action']) {
                SkeletonFiles::REPLACE => 'replaced with the new version\'s',
                SkeletonFiles::CREATE => 'added',
                SkeletonFiles::DELETE => 'removed',
                default => '<comment>edited by the site and changed by the new version: not changed</comment>',
            }), $plan->skeletonFiles));

            if (!$plan->skeletonBaseKnown) {
                $io->note('The skeleton the site came from isn\'t known: every file that differs from the new version\'s is shown as edited.');
            }
        }
    }
}
