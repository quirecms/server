<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;

/**
 * In place of Symfony's console error listener, which prints "[critical] Error thrown while running
 * command …" on the owner's screen for every failure (M-20): an error the server didn't expect goes to
 * the agent's log, with its trace ("quire-server logs agent"); the owner reads its message
 * (Application). The owner's own mistakes (a site that doesn't exist, an option that isn't one) are
 * just said.
 */
final readonly class ErrorLog
{
    /** The agent's own standard error, from a command run in its container ("docker compose exec") */
    public const string AGENT = '/proc/1/fd/2';

    public function __construct(
        private string $log = self::AGENT,
    ) {
    }

    #[\Symfony\Component\EventDispatcher\Attribute\AsEventListener(event: ConsoleEvents::ERROR, priority: -128)]
    public function onError(ConsoleErrorEvent $event): void
    {
        $error = $event->getError();

        if ($error instanceof ConsoleException || Application::expected($error)) {
            return;
        }

        $line = \sprintf("[%s] %s failed: %s\n%s\n", gmdate('Y-m-d H:i:s'), $event->getCommand()?->getName() ?? 'A command', $error->getMessage(), $error->getTraceAsString());
        @file_put_contents($this->log, $line, \FILE_APPEND);
    }
}
