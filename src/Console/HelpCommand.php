<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * "quire-server help" (OWNER-11, NOVICE-06): the commands as an owner types them, by what they're
 * for, with the server's own (up, down, logs, ps) that the "quire-server" command runs itself; not
 * Symfony's listing, its version, its options or the agent's internal commands.
 */
#[AsCommand(name: 'server:help', description: 'The commands, by what they\'re for', hidden: true)]
final class HelpCommand extends Command
{
    /** What the "quire-server" command runs itself, without the agent */
    private const array HOST = [
        'up' => 'Start the server, or apply changes to server.env',
        'down' => 'Stop the server (sites stay offline until "up"; their data stays)',
        'ps' => 'The server\'s containers, and whether they\'re healthy',
        'logs' => 'What the server\'s containers said ("logs agent", "logs front"…)',
    ];

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $application = $this->getApplication() ?? new Application();
        $groups = ['Sites' => [], 'Backups' => [], 'The server' => []];

        foreach ($application->all('server') as $command) {
            $name = (string) $command->getName();

            if ($command->isHidden() || !str_starts_with($name, 'server:')) {
                continue;
            }

            $short = substr($name, \strlen('server:'));
            $group = match (true) {
                str_starts_with($short, 'site:') => 'Sites',
                str_starts_with($short, 'backups') => 'Backups',
                default => 'The server',
            };
            $groups[$group][$short] = $command->getDescription();
        }

        $groups['The server'] += self::HOST;
        $io->writeln('quire-server <command> [options]: "quire-server <command> --help" says more of one.');

        foreach ($groups as $title => $commands) {
            ksort($commands);
            $io->section($title);
            $io->definitionList(...array_map(static fn (string $name, string $description): array => [$name => $description], array_keys($commands), $commands));
        }

        return Command::SUCCESS;
    }
}
