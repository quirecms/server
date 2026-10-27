<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Site\SiteName;
use Quire\Server\Update\SiteUpdater;
use Quire\Server\Update\UpdateFailed;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:deploy',
    description: 'Deploy a site from its Git repository: its branch\'s newest commit, or one you name',
    help: <<<'HELP'
        For a site that follows a Git repository. The commit is built while the site keeps running,
        the site is backed up, the new version started (a few seconds offline; the front door tells
        visitors) and checked. If it fails, it's rolled back by itself. Undo it within a week with
        "site:undo".

          <info>quire-server site:deploy blog</info>
          <info>quire-server site:deploy blog --commit=4f2c…</info>   (a commit of its branch, all 40 characters)

        The agent can deploy new commits by itself: "quire-server site:poll blog on".
        HELP,
)]
final readonly class DeployCommand
{
    public function __construct(
        private SiteUpdater $updater,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name, e.g. "blog"')]
        string $name,
        #[Option(description: 'A commit of its branch (its newest otherwise)')]
        ?string $commit = null,
    ): int {
        try {
            $site = new SiteName($name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->title(\sprintf('Deploying "%s"', $site));

        try {
            $deployed = $this->updater->deploy($site, $commit, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (UpdateFailed $e) {
            $io->error($e->restored
                ? ['The deploy failed, and the site is as it was before:', $e->getMessage(), 'Its log: quire-server operations '.$e->operationId]
                : ['The deploy failed, and so did putting the site back: it needs you.', $e->getMessage(), 'Its log (and where its backup is): quire-server operations '.$e->operationId]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error(['The site wasn\'t deployed:', $e->getMessage()]);

            return Command::FAILURE;
        }

        if (null === $deployed) {
            $io->success(\sprintf('"%s" runs that commit already.', $site));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('"%s" runs commit %s.', $site, substr($deployed, 0, 12)));
        $io->writeln(\sprintf('Undo it within a week: quire-server site:undo %s', $site));

        return Command::SUCCESS;
    }
}
