<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Site\SiteName;
use Quire\Server\Update\SiteUpdater;
use Quire\Server\Update\UpdatePlanner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:audit',
    description: 'Known vulnerabilities in the packages a site runs',
    help: <<<'HELP'
        Checks every package of the site (Quire, extensions, everything else) against the vulnerabilities
        Packagist knows of. "site:update" shows which an update fixes.

          <info>quire-server site:audit jane</info>
        HELP,
)]
final readonly class AuditSiteCommand
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
    ): int {
        try {
            $site = new SiteName($name);
            $this->updater->prepare($site);
            $advisories = $this->planner->audit($site);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $advisories) {
            $io->success(\sprintf('No known vulnerabilities in "%s".', $site));

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($advisories as $package => $list) {
            foreach ($list as $advisory) {
                $rows[] = [$package, $advisory['severity'] ?? '?', $advisory['title'], $advisory['cve'] ?? '', $advisory['link'] ?? ''];
            }
        }

        $io->table(['Package', 'Severity', 'Advisory', 'CVE', 'Link'], $rows);
        $io->warning(\sprintf('"%s" runs packages with known vulnerabilities: "quire-server site:update %s" shows which an update fixes.', $site, $site));

        // Like composer audit: non-zero when something is affected.
        return Command::FAILURE;
    }
}
