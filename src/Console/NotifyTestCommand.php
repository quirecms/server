<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Notify\Notifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:notify:test',
    description: 'Send a test notification by email and to the webhook, as server.env sets them',
)]
final readonly class NotifyTestCommand
{
    public function __construct(
        private Notifier $notifier,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->notifier->configured()) {
            $io->error('Nobody is told of problems: set QUIRE_SERVER_NOTIFY_EMAIL (with QUIRE_SERVER_MAILER_DSN) or QUIRE_SERVER_NOTIFY_WEBHOOK in server.env, then "quire-server up". "quire-server status" says what\'s set.');

            return Command::FAILURE;
        }

        $failed = false;

        foreach ($this->notifier->test() as $channel => $error) {
            if (null === $error) {
                $io->writeln(\sprintf('Sent: %s', $channel));
            } else {
                $io->writeln(\sprintf('<error>Not sent:</error> %s', $error));
                $failed = true;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
