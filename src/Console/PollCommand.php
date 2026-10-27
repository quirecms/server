<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Registry\SiteGit;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:poll',
    description: 'Whether the agent deploys a site\'s new commits by itself',
    help: <<<'HELP'
        For a site that follows a Git repository: when on, the agent asks the repository for new commits
        of the site's branch every few minutes (QUIRE_SERVER_GIT_POLL_SECONDS, 300 by default) and
        deploys them. A commit that fails isn't tried again by itself; the next one is.

          <info>quire-server site:poll blog on</info>
          <info>quire-server site:poll blog off</info>
        HELP,
)]
final readonly class PollCommand
{
    public function __construct(
        private SiteGit $repositories,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name, e.g. "blog"')]
        string $name,
        #[Argument(description: '"on" or "off"')]
        string $state,
    ): int {
        if (!\in_array($state, ['on', 'off'], true)) {
            $io->error('Say "on" or "off".');

            return Command::INVALID;
        }

        try {
            $site = new SiteName($name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if (null === $this->repositories->of($site)) {
            $io->error(\sprintf('"%s" doesn\'t follow a Git repository.', $site));

            return Command::FAILURE;
        }

        $this->repositories->setPolling($site, 'on' === $state);
        $io->success('on' === $state ? \sprintf('The agent deploys new commits of "%s" by itself.', $site) : \sprintf('"%s" is deployed only when asked.', $site));

        return Command::SUCCESS;
    }
}
