<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Operation\SiteManager;
use Quire\Server\Site\Domain;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:restore-removed',
    description: 'Bring back a removed site, with its data as it was when it was removed',
    help: <<<'HELP'
        Removing a site keeps its final backup (/srv/quire/backups/<site>/final-<time>.tar) and its
        folder (/srv/quire/sites/.removed/<site>-<time>). This brings it back from them: its code and
        files as its folder has them, its data from its final backup, its domains as it had them (or
        --domain=…, when another site took one meanwhile). It gets settings of its own (its database,
        password and secret): everyone signs in again.

          <info>quire-server site:restore-removed jane</info>
          <info>quire-server site:restore-removed jane --domain=jane.example --domain=www.jane.example</info>

        A Git mode site comes from its repository instead: add it from there, then put its data back
        with "quire-server backups:restore <site> --file=final-<time>.tar".
        HELP,
)]
final readonly class RestoreRemovedCommand
{
    public function __construct(
        private SiteManager $sites,
    ) {
    }

    /**
     * @param list<string> $domain
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The removed site\'s name ("quire-server site:list --removed")')]
        string $name,
        #[Option(description: 'Its domains (repeatable; the first is the main one): those it had otherwise')]
        array $domain = [],
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
    ): int {
        try {
            $site = new SiteName($name);
            $domains = array_map(static fn (string $value): Domain => new Domain($value), $domain);

            if (!$yes && !$io->confirm(\sprintf('Bring back "%s", as it was when it was removed?', $site), true)) {
                $io->writeln('Nothing changed.');

                return Command::SUCCESS;
            }

            $restored = $this->sites->restoreRemoved($site, $domains, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (\Throwable $e) {
            $io->error(['The site wasn\'t brought back (everything done was undone):', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success(\sprintf('"%s" runs again, from %s and %s.', $site, basename($restored['folder']), basename($restored['backup'])));
        $io->writeln(\sprintf('Its domains: %s. Everyone signs in again (it has a secret of its own).', implode(', ', array_map(static fn (Domain $domain): string => $domain->unicode(), $restored['site']->domains))));

        return Command::SUCCESS;
    }
}
