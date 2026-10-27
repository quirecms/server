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
    name: 'server:site:adopt',
    description: 'Take over a Quire site already running on this machine, behind the front door',
    help: <<<'HELP'
        For a site installed from quire/skeleton before this server, in /srv/quire/sites/<name> with its
        prod.env (move its folder there first). It's backed up, built with the server's settings while
        it keeps running, then restarted behind the front door: offline a few seconds. Its files stay
        as they are but two lines of prod.env (COMPOSE_FILE gets compose.server.yaml, and
        COMPOSE_PROJECT_NAME is set; the old file is kept as prod.env.before-server). Its database
        stays its own. If anything fails, it's put back as it was.

          <info>quire-server site:adopt demo</info>                         (its domains from SERVER_NAME)
          <info>quire-server site:adopt jane --domain=jane.example --domain=www.jane.example</info>

        A site that holds ports 80 and 443 itself must be adopted before the front door starts
        ("quire-server up" starts it once no site holds them). install.sh does this in that order.
        HELP,
)]
final readonly class AdoptSiteCommand
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
        #[Argument(description: 'The site\'s short name on the server: its folder\'s name in /srv/quire/sites')]
        string $name,
        #[Option(description: 'A domain of the site (repeatable), the first the main one; those of its SERVER_NAME otherwise')]
        array $domain = [],
    ): int {
        try {
            $site = new SiteName($name);
            $domains = array_map(static fn (string $value): Domain => new Domain($value), $domain);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->title(\sprintf('Adopting the site "%s"', $site));

        try {
            $adopted = $this->sites->adopt($site, $domains, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (\Throwable $e) {
            $io->error(['Adopting the site failed, and it\'s back as it was:', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success(\sprintf('"%s" runs behind the front door, at %s.', $adopted->name, implode(', ', array_map(static fn (Domain $domain): string => $domain->unicode(), $adopted->domains))));
        $io->writeln('Its certificate comes from the front door at the next visit, once the front door runs ("quire-server up").');

        return Command::SUCCESS;
    }
}
