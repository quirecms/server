<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Update\AddedPackages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * For the updater, which doesn't mount sites' folders: the managed sites that have third-party
 * packages (site:packages … --third-party), as JSON, site => packages. Undoing an update to 0.6 turns
 * their automatic updates off, since 0.5 doesn't hold those packages where they are (G1).
 */
#[AsCommand(
    name: self::NAME,
    description: 'The managed sites with third-party packages, as JSON (for the updater)',
    hidden: true,
)]
final readonly class ThirdPartySitesCommand
{
    public const string NAME = 'server:packages:third-party';

    public function __construct(
        private SiteRegistry $registry,
        private SiteGit $repositories,
        private ServerPaths $paths,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $sites = [];

        foreach ($this->registry->all() as $site) {
            if (Site::REMOVED === $site->status || null !== $this->repositories->of($site->name)) {
                continue;
            }

            try {
                $added = AddedPackages::of(AddedPackages::read($this->paths->site($site->name).'/composer.json'));
            } catch (\RuntimeException) {
                continue; // No composer.json of the server's: nothing it added
            }

            $thirdParty = array_keys(array_filter($added));

            if ([] !== $thirdParty) {
                $sites[$site->name->value] = $thirdParty;
            }
        }

        ksort($sites);
        $io->writeln(json_encode((object) $sites, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), SymfonyStyle::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
