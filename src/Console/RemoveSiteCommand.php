<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Operation\SiteManager;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:remove',
    description: 'Remove a site: backed up first, its folder archived',
    help: <<<'HELP'
        Backs the site up to /srv/quire/backups/<site>/final-<time>.tar, closes it to visitors, removes
        its containers, volumes and database, and moves its folder to /srv/quire/sites/.removed/. Asks
        you to type the site's name, unless you pass <comment>--yes</comment>:

          <info>quire-server site:remove jane</info>

        A site that can't start can't be backed up: <comment>--skip-backup</comment> removes it anyway, and its
        data is gone.
        HELP,
)]
final readonly class RemoveSiteCommand
{
    public function __construct(
        private SiteManager $sites,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'The site\'s name')]
        string $name,
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
        #[Option(description: 'Remove it without a final backup: its data is lost')]
        bool $skipBackup = false,
    ): int {
        try {
            $site = new SiteName($name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if (!$yes) {
            if (!$input->isInteractive()) {
                $io->error('Pass --yes to remove a site without a terminal.');

                return Command::INVALID;
            }

            $typed = $io->ask(\sprintf('Type "%s" to remove it%s', $site, $skipBackup ? ', WITHOUT a backup' : ''));

            if ($typed !== $site->value) {
                $io->writeln('Nothing was removed.');

                return Command::FAILURE;
            }
        }

        try {
            $backup = $this->sites->remove($site, !$skipBackup, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (\Throwable $e) {
            $io->error(['Removing the site failed:', $e->getMessage(), ...($skipBackup ? [] : ['If it can\'t be backed up because it doesn\'t start, --skip-backup removes it without one.'])]);

            return Command::FAILURE;
        }

        $io->success(\sprintf('"%s" is removed.%s', $site, null === $backup ? '' : ' Its final backup: '.$backup));

        return Command::SUCCESS;
    }
}
