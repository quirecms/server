<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Console;

use PHPUnit\Framework\TestCase;
use Quire\Server\Console\Application;
use Quire\Server\Console\ErrorLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * How an owner reads a command's failure (M-20): a plain message, never an exception's chain or the
 * console logger's "[critical]" line; what the server didn't expect goes to the agent's log.
 */
final class ApplicationTest extends TestCase
{
    public function testPostgresqlDownIsSaidWithWhatToDo(): void
    {
        $error = new \RuntimeException('An exception occurred in the driver: SQLSTATE[08006] [7] could not translate host name "quire-database" to address', 0, new \PDOException('SQLSTATE[08006] [7] could not translate host name'));

        self::assertSame(['The server\'s PostgreSQL isn\'t answering, so nothing was done: every site\'s data is where it was.', '"quire-server ps" shows whether it runs, "quire-server logs database" why it stopped; "quire-server up" starts it again.'], Application::message($error));
        self::assertTrue(Application::expected($error));
    }

    public function testAMistakeIsJustSaidAndTheUnexpectedPointsToTheLog(): void
    {
        self::assertSame(['There\'s no site named "jane".'], Application::message(new \DomainException('There\'s no site named "jane".')));
        self::assertSame(['Undefined array key "x"', 'That wasn\'t expected: "quire-server logs agent" has the details (or run the command again with -v).'], Application::message(new \ErrorException('Undefined array key "x"')));
    }

    public function testNoChainNoFilesNoLines(): void
    {
        $output = new BufferedOutput();
        new Application(new \Quire\Server\Kernel('test', false))->renderThrowable(new \RuntimeException('Outer', 0, new \LogicException('Inner')), $output);

        self::assertStringContainsString('Outer', $output->fetch());
        self::assertStringNotContainsString('.php line', $output->fetch());
    }

    public function testOnlyTheUnexpectedGoesToTheAgentsLog(): void
    {
        $log = sys_get_temp_dir().'/quire-error-log-'.bin2hex(random_bytes(6));
        $listener = new ErrorLog($log);
        $command = new Command('server:site:list');

        $listener->onError(new ConsoleErrorEvent(new ArrayInput([]), new BufferedOutput(), new \DomainException('No such site.'), $command));
        self::assertFileDoesNotExist($log);

        $listener->onError(new ConsoleErrorEvent(new ArrayInput([]), new BufferedOutput(), new \ErrorException('Undefined array key "x"'), $command));
        $written = (string) file_get_contents($log);
        unlink($log);
        self::assertStringContainsString('server:site:list failed: Undefined array key "x"', $written);
        self::assertStringContainsString('#0 ', $written, 'With its trace.');
    }
}
