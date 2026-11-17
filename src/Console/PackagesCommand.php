<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Site\SiteName;
use Quire\Server\Update\PackagePlan;
use Quire\Server\Update\SitePackages;
use Quire\Server\Update\SiteUpdater;
use Quire\Server\Update\UpdateFailed;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:packages',
    description: 'Show a site\'s packages, or add, update or remove a Quire extension or theme',
    help: <<<'HELP'
        A managed site's extensions and themes, from Packagist. Each change is worked out first and
        shown (what it adds, where its code comes from, known vulnerabilities), then, once you agree,
        made as an update is: built while the site keeps running, the site backed up, started (a few
        seconds offline) and checked, rolled back by itself if it fails. Undo it within a week with
        "site:undo".

          <info>quire-server site:packages jane</info>                                   what it has
          <info>quire-server site:packages jane add quire/analytics</info>               one of Quire's
          <info>quire-server site:packages jane add acme/reviews:^1.2 --third-party</info>   anyone else's
          <info>quire-server site:packages jane update acme/reviews --third-party</info>     a newer version of it
          <info>quire-server site:packages jane remove acme/reviews</info>

        A package is installed, not enabled: enable it in the site's admin, where its settings are.
        Adding one moves nothing else: when it needs a newer Quire, update the site first. Only Quire
        extensions and themes are added, never a Composer plugin, nor a package that stands in for one
        the site has or for one of Quire's.

        Quire's packages are checked against its signed release feed when the site is built, and
        follow the site's updates. Anyone else's code runs in the site, with its database and files:
        it's added only with --third-party, shown with the commit its download names, and never moves
        in an update after, automatic or not; only "update" here moves it, with these checks again.

        A package is removed only once the site doesn't run it (disable it, or choose another theme, in
        its admin), and only one added here: what the site came with stays. Sites that follow a Git
        repository get their packages there.
        HELP,
)]
final readonly class PackagesCommand
{
    public function __construct(
        private SitePackages $packages,
        private SiteUpdater $updater,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s name')]
        string $site,
        #[Argument(description: '"add", "update" or "remove" (none: what it has)')]
        ?string $action = null,
        #[Argument(description: 'The package, e.g. "quire/analytics", "acme/reviews:^1.2"')]
        ?string $package = null,
        #[Option(description: 'Allow a package that isn\'t Quire\'s: its code runs in the site')]
        bool $thirdParty = false,
        #[Option(description: 'Only show what would change')]
        bool $dryRun = false,
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
    ): int {
        $say = static fn (string $line) => $io->writeln('  '.$line);

        try {
            $name = new SiteName($site);

            if (null === $action) {
                return null === $package ? $this->list($io, $name) : $this->invalid($io, 'Say what to do with it: add, update or remove.');
            }

            if (!\in_array($action, [PackagePlan::ADD, PackagePlan::UPDATE, PackagePlan::REMOVE], true)) {
                return $this->invalid($io, \sprintf('"%s" isn\'t something to do with a package: add, update or remove.', SiteName::printable($action)));
            }

            if (null === $package) {
                return $this->invalid($io, \sprintf('Name the package to %s, e.g. "quire/analytics".', $action));
            }

            $say('Working out what would change…');
            $plan = match ($action) {
                PackagePlan::ADD => $this->packages->add($name, $package, $thirdParty),
                PackagePlan::UPDATE => $this->packages->update($name, $package, $thirdParty),
                default => $this->packages->remove($name, $package),
            };
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($io, $e->getMessage());
        } catch (\Throwable $e) {
            $io->error(['Nothing changed:', $e->getMessage()]);

            return Command::FAILURE;
        }

        $this->show($io, $plan);

        if ($dryRun || (!$yes && !$io->confirm(match ($plan->action) {
            PackagePlan::ADD => \sprintf('Add %s to "%s"?', $plan->package, $name),
            PackagePlan::UPDATE => \sprintf('Move %s to %s on "%s"?', $plan->package, $plan->version ?? '?', $name),
            default => \sprintf('Remove %s from "%s"?', $plan->package, $name),
        }, false))) {
            $plan->discard();
            $io->writeln('Nothing changed.');

            return Command::SUCCESS;
        }

        try {
            $this->updater->update($plan->plan, false, Requester::CHANNEL, Requester::name(), static fn (string $step) => $say($step.'…'));
        } catch (UpdateFailed $e) {
            $io->error($e->restored
                ? ['It failed, and the site is as it was before:', $e->getMessage(), 'Its log: quire-server operations '.$e->operationId]
                : ['It failed, and so did putting the site back: it needs you.', $e->getMessage(), 'Its log (and where its backup is): quire-server operations '.$e->operationId]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error(['Nothing changed:', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success(match ($plan->action) {
            PackagePlan::ADD => \sprintf('"%s" has %s %s: installed, not enabled. Enable it in its admin (Extensions, or Themes for a theme), where its settings are.', $name, $plan->package, $plan->version ?? ''),
            PackagePlan::UPDATE => \sprintf('"%s" runs %s %s.', $name, $plan->package, $plan->version ?? ''),
            default => \sprintf('"%s" doesn\'t have %s any more.', $name, $plan->package),
        });
        $io->writeln(\sprintf('Undo it within a week: quire-server site:undo %s', $name));

        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io, SiteName $name): int
    {
        $rows = $this->packages->list($name);
        $io->table(['Package', 'Requires', 'Version', 'Type', 'Added'], array_map(static fn (array $row): array => [
            $row['package'],
            $row['constraint'],
            $row['version'] ?? '-',
            $row['type'] ?? '-',
            match ($row['added']) {
                'third-party' => 'with site:packages, a third party\'s: moves only with "update" here',
                'quire' => 'with site:packages',
                default => 'with the site',
            },
        ], $rows));
        $io->writeln(\sprintf('Whether the site runs each extension, and its theme: its admin, or "quire-server site:console %s quire:about".', $name));

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, PackagePlan $plan): void
    {
        $io->section(match ($plan->action) {
            PackagePlan::ADD => \sprintf('Adding %s %s%s', $plan->package, $plan->version ?? '', $plan->thirdParty ? ': a third party\'s, not Quire\'s' : ''),
            PackagePlan::UPDATE => \sprintf('Moving %s %s → %s: a third party\'s, not Quire\'s', $plan->package, $plan->plan->from->versions[$plan->package] ?? '?', $plan->version ?? '?'),
            default => \sprintf('Removing %s %s', $plan->package, $plan->plan->from->versions[$plan->package] ?? ''),
        });

        if ([] !== $plan->plan->packages) {
            $io->table(['Package', 'From', 'To'], array_map(static fn (array $change): array => [$change['package'], $change['from'] ?? '(new)', $change['to'] ?? '(removed)'], $plan->plan->packages));
        }

        foreach ($plan->arrived as $arrival) {
            $io->writeln(\sprintf('  <info>New</info> %s %s (%s), from %s', $arrival['package'], $arrival['version'], $arrival['type'], $arrival['source'] ?? $arrival['download'] ?? '?'));
            $io->writeln($arrival['quire']
                ? '       Quire\'s: checked against its signed release feed when the site is built.'
                : \sprintf('       Not Quire\'s. Its code is commit %s, downloaded over HTTPS from %s: that commit is what pins it (Packagist gives GitHub downloads no checksum).', $arrival['reference'] ?? '?', $arrival['download'] ?? '?'));
        }

        foreach ($plan->plan->advisoriesAfter as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $io->writeln(\sprintf('  <comment>Still affected</comment> %s: %s%s', $package, $advisory['title'], null === $advisory['link'] ? '' : ' '.$advisory['link']));
            }
        }

        if (PackagePlan::ADD === $plan->action && $plan->thirdParty) {
            $io->note('Its code runs in the site, with the site\'s database and files: add it only from someone you trust. It doesn\'t move in the site\'s updates; "site:packages … update" moves it.');
        }
    }

    private function invalid(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::INVALID;
    }
}
