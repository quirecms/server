<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\UpdatePolicies;
use Quire\Server\Site\SiteName;
use Quire\Server\Update\AutomaticUpdates;
use Quire\Server\Update\ReleaseCheck;
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
        fails, it's rolled back by itself. Undo it within a week with "site:undo".

          <info>quire-server site:update jane</info>              newest versions its composer.json allows
          <info>quire-server site:update jane --to=0.2.0</info>   another Quire release (e.g. a new minor version)
          <info>quire-server site:update jane --dry-run</info>    only show what would change
          <info>quire-server site:update --all</info>             every site the server updates (not Git mode ones)
          <info>quire-server site:update --all --automatic</info> now, what each site's nightly automatic update does

        An update that changes Quire's version is checked against Quire's signed release feed: what
        the release fixes and what to do are shown, and a release this server can't run (a newer server
        contract, a newer PostgreSQL than the server's) is refused.

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
        private SiteGit $repositories,
        private SiteRegistry $registry,
        private ReleaseCheck $releases,
        private UpdatePolicies $policies,
        private AutomaticUpdates $automatic,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name, e.g. "jane"')]
        ?string $name = null,
        #[Option(description: 'A Quire release to move to, e.g. "0.2.0"')]
        ?string $to = null,
        #[Option(description: 'Only show what would change')]
        bool $dryRun = false,
        #[Option(description: 'Keep the skeleton\'s files the site edited, though the new version changes them')]
        bool $keepEditedFiles = false,
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
        #[Option(description: 'Every site the server updates, one after the other')]
        bool $all = false,
        #[Option(description: 'With --all: what each site\'s automatic updates would do tonight, now')]
        bool $automatic = false,
    ): int {
        if ($all) {
            return null !== $name || null !== $to
                ? $this->invalid($io, '--all updates every site within its own constraints: give no site, and no --to.')
                : ($automatic ? $this->automatic($io) : $this->all($io, $dryRun, $keepEditedFiles, $yes));
        }

        if (null === $name || $automatic) {
            return $this->invalid($io, null === $name ? 'Name a site, or --all.' : '--automatic goes with --all.');
        }

        try {
            $site = new SiteName($name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if (!$dryRun && null !== $this->repositories->of($site)) {
            $io->error(\sprintf('"%s" follows a Git repository: its updates are made there (composer update, committed), then deployed with "site:deploy". "--dry-run" shows what\'s available.', $site));

            return Command::FAILURE;
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
        $registered = $this->registry->get($site);
        $check = $registered instanceof \Quire\Server\Site\Site ? $this->releases->check($plan, $registered) : null;

        if (null !== $check) {
            $this->showCheck($io, $check);

            if ([] !== $check['blocked']) {
                $plan->discard();
                $io->error(['This server can\'t run that release:', ...$check['blocked']]);

                return Command::FAILURE;
            }
        }

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
        $io->writeln(\sprintf('Undo it within a week: quire-server site:undo %s', $site));

        return Command::SUCCESS;
    }

    /**
     * Every site the server updates, one after the other: each one's plan shown, then made, unless it
     * changes nothing, conflicts with files the site edited, or is a release this server can't run.
     */
    private function all(SymfonyStyle $io, bool $dryRun, bool $keepEdited, bool $yes): int
    {
        $sites = $this->policies->updatable();

        if ([] === $sites) {
            $io->success('There\'s no site to update (Git mode sites are updated in their repository).');

            return Command::SUCCESS;
        }

        if (!$dryRun && !$yes && !$io->confirm(\sprintf('Update %d site(s) (%s), one after the other? Each is backed up first, and rolled back by itself if it fails.', \count($sites), implode(', ', array_map(strval(...), $sites))), false)) {
            $io->writeln('Nothing changed.');

            return Command::SUCCESS;
        }

        $outcomes = [];

        foreach ($sites as $site) {
            $io->section(\sprintf('"%s"', $site));
            $outcomes[] = [$site->value, ...$this->allOne($io, $site, $dryRun, $keepEdited)];
        }

        $io->table(['Site', 'Outcome', ''], $outcomes);

        return \in_array(AutomaticUpdates::FAILED, array_column($outcomes, 1), true) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array{string, string} The outcome, and why
     */
    private function allOne(SymfonyStyle $io, SiteName $site, bool $dryRun, bool $keepEdited): array
    {
        try {
            $this->updater->prepare($site);
            $plan = $this->planner->plan($site);
        } catch (\Throwable $e) {
            return [AutomaticUpdates::FAILED, 'working out the update: '.$e->getMessage()];
        }

        $this->show($io, $plan);
        $registered = $this->registry->get($site);
        $check = $registered instanceof \Quire\Server\Site\Site ? $this->releases->check($plan, $registered) : null;

        if (null !== $check) {
            $this->showCheck($io, $check);
        }

        $skip = match (true) {
            !$plan->changesAnything() => AutomaticUpdates::UP_TO_DATE,
            null !== $check && [] !== $check['blocked'] => implode(' ', $check['blocked']),
            [] !== $plan->conflicts() && !$keepEdited => 'it edited files of the skeleton the new version changes',
            $dryRun => 'only shown (--dry-run)',
            default => null,
        };

        if (null !== $skip) {
            $plan->discard();

            return AutomaticUpdates::UP_TO_DATE === $skip ? [AutomaticUpdates::UP_TO_DATE, ''] : [AutomaticUpdates::SKIPPED, $skip];
        }

        try {
            $this->updater->update($plan, $keepEdited, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (UpdateFailed $e) {
            return [AutomaticUpdates::FAILED, ($e->restored ? 'rolled back: ' : 'needs you: ').$e->getMessage()];
        } catch (\Throwable $e) {
            return [AutomaticUpdates::FAILED, $e->getMessage()];
        }

        return [AutomaticUpdates::UPDATED, null !== $plan->quireTo() && $plan->quireFrom() !== $plan->quireTo() ? 'Quire '.$plan->quireTo() : ''];
    }

    private function automatic(SymfonyStyle $io): int
    {
        $outcomes = $this->automatic->run(true, Requester::CHANNEL, static fn (string $line) => $io->writeln('  '.$line));

        if ([] === $outcomes) {
            $io->success('No site gets automatic updates (see "site:updates").');

            return Command::SUCCESS;
        }

        $io->table(['Site', 'Outcome', ''], array_map(array_values(...), $outcomes));

        return \in_array(AutomaticUpdates::FAILED, array_column($outcomes, 'outcome'), true) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array{vouched: bool, blocked: list<string>, notes: list<string>, security: list<string>} $check
     */
    private function showCheck(SymfonyStyle $io, array $check): void
    {
        foreach ($check['security'] as $line) {
            $io->writeln('  <info>Security</info> '.$line);
        }

        foreach ($check['notes'] as $line) {
            $io->writeln('  <comment>Note</comment> '.$line);
        }
    }

    private function invalid(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::INVALID;
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
