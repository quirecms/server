<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Notify\Problems;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * One line after the owner's own commands when problems need them (D-35): whatever they came to do,
 * they see it. Not after the agent's own runs, scripts' quiet ones, or "status" itself.
 */
#[AsEventListener(event: ConsoleEvents::TERMINATE)]
final readonly class ProblemsBanner
{
    /** The agent's, the host command's own and the one that shows them */
    private const array SILENT = ['server:agent', 'server:busy', 'server:status', 'server:version', 'server:database-logins', 'server:setup', 'server:links'];

    public function __construct(
        private Problems $problems,
    ) {
    }

    public function __invoke(ConsoleTerminateEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        $output = $event->getOutput();

        if (null === $name || !str_starts_with($name, 'server:') || \in_array($name, self::SILENT, true) || $output->getVerbosity() < OutputInterface::VERBOSITY_NORMAL) {
            return;
        }

        try {
            $open = \count($this->problems->open());
        } catch (\Throwable) {
            // The registry unreachable is said by the command itself
            return;
        }

        if ($open > 0) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln(\sprintf('<comment>%d %s: "quire-server status"</comment>', $open, 1 === $open ? 'problem needs you' : 'problems need you'));
        }
    }
}
