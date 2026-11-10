<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\ServerUpdate\ServerFolder;
use Quire\Server\ServerUpdate\ServerSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:settings',
    description: 'Show the server\'s settings (server.env), without its secrets',
    help: <<<'HELP'
        Every setting of server.env, with its value: secrets only say whether they're set, a mailer's
        DSN shows without its password. Those marked "change" are changed with "quire-server
        settings:set"; the others say what sets them.
        HELP,
)]
final readonly class SettingsCommand
{
    public function __construct(
        private ServerFolder $folder,
        private bool $inUpdater,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->inUpdater) {
            $io->error('The settings are read from the updater (it holds server.env): run "quire-server settings" on the machine.');

            return Command::FAILURE;
        }

        $rows = [];

        foreach (ServerSettings::shown($this->folder->env()) as $name => $value) {
            $rows[] = [$name, $value, isset(ServerSettings::CHANGEABLE[$name]) ? 'change' : ''];
        }

        $io->table(['Setting', 'Value', ''], $rows);
        $io->writeln('"quire-server settings:set NAME=value" changes one (a secret\'s value is asked for: "quire-server settings:set MAILER_DSN").');

        return Command::SUCCESS;
    }
}
