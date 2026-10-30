<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Console;

use Quire\Server\Console\BusyCommand;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What "quire-server up" and "down" ask before they recreate or stop the agent, on the registry's real
 * tables: whether an operation runs, which they would cut short.
 */
final class BusyCommandTest extends RegistryTestCase
{
    public function testAnOperationThatRunsIsNamed(): void
    {
        $operations = new OperationLog($this->connection, $this->schema);
        $deploy = $operations->start('site.deploy', new SiteName('blog'), [], 'cli', 'darko');
        $tester = $this->tester($operations);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame("site.deploy of blog\n", $tester->getDisplay());

        $deploy->succeed();
        self::assertSame(Command::SUCCESS, $tester->execute([]), 'Nothing runs: they go ahead.');
        self::assertSame('', $tester->getDisplay());
    }

    private function tester(OperationLog $operations): CommandTester
    {
        $application = new Application();
        $application->addCommand(new BusyCommand($operations));

        return new CommandTester($application->find('server:busy'));
    }
}
