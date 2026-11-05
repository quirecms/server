<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Operation\SiteManager;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:domains',
    description: 'Show a site\'s domains, or give it others',
    help: <<<'HELP'
        Without --domain, shows the site's domains, the main one first. With it, the site gets these
        domains instead, all of them (the first is the main one): it's started again with them, a few
        seconds offline, and the front door serves the new ones and no longer the old. Point a new
        domain's DNS at this server first: its certificate comes on its first visit.

          <info>quire-server site:domains jane</info>
          <info>quire-server site:domains jane --domain=jane.example --domain=www.jane.example</info>

        If anything fails, the site is put back as it was.
        HELP,
)]
final readonly class DomainsCommand
{
    public function __construct(
        private SiteManager $sites,
        private SiteRegistry $registry,
    ) {
    }

    /**
     * @param list<string> $domain
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s name ("quire-server site:list")')]
        string $name,
        #[Option(description: 'Its domains from now on (repeatable; the first is the main one)')]
        array $domain = [],
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
    ): int {
        try {
            $site = new SiteName($name);
            $domains = array_map(static fn (string $value): Domain => new Domain($value), $domain);
            $current = $this->registry->get($site);

            if (!$current instanceof Site || Site::REMOVED === $current->status) {
                throw new \DomainException(\sprintf('There\'s no site named "%s".', $site));
            }

            if ([] === $domains) {
                $io->writeln($this->listed($current));

                return Command::SUCCESS;
            }

            if (!$yes && !$io->confirm(\sprintf('Give "%s" the domains %s instead of %s? It\'s offline for a few seconds.', $site, $this->names($domains), $this->names($current->domains)), true)) {
                $io->writeln('Nothing changed.');

                return Command::SUCCESS;
            }

            $changed = $this->sites->setDomains($site, $domains, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (\Throwable $e) {
            $io->error(['The domains weren\'t changed (everything done was undone):', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success(\sprintf('"%s" answers at %s.', $site, $this->names($changed->domains)));
        $io->writeln('A new domain gets its certificate on its first visit, once its DNS points at this server.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function listed(Site $site): array
    {
        $lines = [];

        foreach ($site->domains as $index => $domain) {
            $lines[] = $domain->unicode().(0 === $index ? ' (main)' : '');
        }

        return $lines;
    }

    /**
     * @param list<Domain> $domains
     */
    private function names(array $domains): string
    {
        return implode(', ', array_map(static fn (Domain $domain): string => $domain->unicode(), $domains));
    }
}
