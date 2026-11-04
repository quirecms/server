<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The server's commands, as an owner reads their failures (M-20): what happened, whether the data is
 * safe and what to do, rather than an exception's chain and the files it went through. With -v, the
 * chain as Symfony renders it; the agent's log has it anyway (ErrorLog).
 */
final class Application extends FrameworkApplication
{
    #[\Override]
    public function renderThrowable(\Throwable $e, OutputInterface $output): void
    {
        // The console's own (an unknown command or option): its usage helps
        if ($e instanceof ConsoleException || $output->isVerbose()) {
            parent::renderThrowable($e, $output);

            return;
        }

        $io = new SymfonyStyle(new ArrayInput([]), $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
        $io->error(self::message($e));
    }

    /**
     * @return list<string> What an owner reads of an error
     */
    public static function message(\Throwable $e): array
    {
        if (self::databaseDown($e)) {
            return ['The server\'s PostgreSQL isn\'t answering, so nothing was done: every site\'s data is where it was.', '"quire-server ps" shows whether it runs, "quire-server logs database" why it stopped; "quire-server up" starts it again.'];
        }

        if (self::expected($e)) {
            return [$e->getMessage()];
        }

        return [$e->getMessage(), 'That wasn\'t expected: "quire-server logs agent" has the details (or run the command again with -v).'];
    }

    /**
     * Whether it's a failure the server knows and says plainly (what was asked can't be done, a tool
     * failed, PostgreSQL is down), not a fault of its own.
     */
    public static function expected(\Throwable $e): bool
    {
        return self::databaseDown($e)
            || $e instanceof \DomainException
            || $e instanceof \InvalidArgumentException
            || $e instanceof \Quire\Server\Process\ProcessFailed
            || $e instanceof \Quire\Server\Process\ProcessTimedOut
            || $e instanceof \Quire\Server\Backup\BackupFailed
            || $e instanceof \Quire\Server\Update\UpdateFailed
            || $e instanceof \Quire\Server\Update\CodeNotReleased
            || $e instanceof \Quire\Server\Release\ReleaseVerificationFailed
            || $e instanceof \Quire\Server\Release\FeedNotTrusted;
    }

    /** PostgreSQL unreachable: a connection that can't be made, wherever it was asked for */
    private static function databaseDown(\Throwable $e): bool
    {
        for ($cause = $e; $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof \Doctrine\DBAL\Exception\ConnectionException || 1 === preg_match('/SQLSTATE\[08\d{3}\]/', $cause->getMessage())) {
                return true;
            }
        }

        return false;
    }
}
