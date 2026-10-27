<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Registry\SiteUpdates;
use Quire\Server\Site\SiteName;
use Quire\Server\Update\SiteUpdater;
use Quire\Server\Update\UpdateFailed;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:undo',
    description: 'Undo a site\'s last update or deploy (within a week)',
    help: <<<'HELP'
        Puts back the site's files and image from before its last update (or, in Git mode, its last
        deploy). If that changed its database (its migrations ran, or it isn't known), its backup from
        before is restored too, and what changed on the site since is lost: you're told first.

          <info>quire-server site:undo jane</info>
        HELP,
)]
final readonly class UndoCommand
{
    public function __construct(
        private SiteUpdates $updates,
        private SiteUpdater $updater,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name, e.g. "jane"')]
        string $name,
        #[Option(description: 'Don\'t ask (also when its changes since the update would be lost)')]
        bool $yes = false,
    ): int {
        try {
            $site = new SiteName($name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $update = $this->updates->undoable($site);

        if (null === $update) {
            $io->error('The site has nothing to undo (only its last update or deploy can be undone, for a week).');

            return Command::FAILURE;
        }

        $what = SiteUpdates::DEPLOY === $update['kind'] ? 'deploy' : 'update';
        $io->title(\sprintf('Undoing the %s of "%s" from %s', $what, $site, $update['created_at']));

        if (SiteUpdates::DEPLOY === $update['kind']) {
            $io->writeln(\sprintf('  Back to commit %s', substr((string) $update['commit_before'], 0, 12)));
        } else {
            $io->listing(array_map(static fn (array $change): string => \sprintf('%s: back to %s', $change['package'], $change['from'] ?? '(none)'), $update['packages']));
        }
        $restore = false !== $update['migrations_ran'];

        if ($restore) {
            $io->warning(\sprintf('That %s %s the database: its backup from before is restored, and what changed on the site since is lost.', $what, null === $update['migrations_ran'] ? 'may have changed' : 'changed'));
        }

        if (!$yes && !$io->confirm($restore ? 'Undo it, losing the changes since?' : 'Undo it?', false)) {
            $io->writeln('Nothing changed.');

            return Command::SUCCESS;
        }

        try {
            $this->updater->undo($site, true, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (UpdateFailed $e) {
            $io->error($e->restored
                ? [\sprintf('Undoing the %s failed, and the site is as it was:', $what), $e->getMessage(), 'Its log: quire-server operations '.$e->operationId]
                : [\sprintf('Undoing the %s failed, and so did putting the site back: it needs you.', $what), $e->getMessage(), 'Its log: quire-server operations '.$e->operationId]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('"%s" is as it was before its %s.', $site, $what));

        return Command::SUCCESS;
    }
}
