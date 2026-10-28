<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\UpdatePolicies;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:updates',
    description: 'Which updates a site gets by itself, every night: security (the default), all, or off',
    help: <<<'HELP'
        Every night (from QUIRE_SERVER_UPDATE_HOUR, UTC, in server.env: 3 by default; "off" for none on
        this server), each site gets
        the updates its policy says, as "site:update" makes them: built beside, backed up, checked,
        and rolled back by itself if they fail. The operations' record says what was done.

          <info>quire-server site:updates jane</info>            which it gets
          <info>quire-server site:updates jane security</info>   only those that fix a known vulnerability, of the package that has it (the default)
          <info>quire-server site:updates jane all</info>        everything its composer.json allows
          <info>quire-server site:updates jane off</info>        none ("site:update" by hand)

        Never by itself: an update that changes files of the skeleton the site edited, or Quire's
        version without Quire's signed release feed vouching for it. Git mode sites don't get any:
        their updates are made in their repository.
        HELP,
)]
final readonly class UpdatePolicyCommand
{
    public function __construct(
        private UpdatePolicies $policies,
        private SiteGit $repositories,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name, e.g. "jane"')]
        string $name,
        #[Argument(description: 'security, all or off')]
        ?string $policy = null,
    ): int {
        try {
            $site = new SiteName($name);

            if (null === $policy) {
                $io->writeln(\sprintf('"%s" gets %s.', $site, $this->describe($this->policies->of($site))));

                return Command::SUCCESS;
            }

            $this->policies->set($site, $policy);
        } catch (\InvalidArgumentException|\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('"%s" gets %s.', $site, $this->describe($policy)));

        if (null !== $this->repositories->of($site)) {
            $io->note('It follows a Git repository: its updates are made there, so it gets none by itself whatever this says.');
        }

        return Command::SUCCESS;
    }

    private function describe(string $policy): string
    {
        return match ($policy) {
            UpdatePolicies::ALL => 'every update its composer.json allows, every night',
            UpdatePolicies::OFF => 'no update by itself',
            default => 'the updates that fix a known vulnerability, every night',
        };
    }
}
