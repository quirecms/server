<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Console;

use Doctrine\DBAL\DriverManager;
use Quire\Server\Console\AgentCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The agent never stops for a step of its round that fails, e.g. one that reads the registry while
 * PostgreSQL restarts: it says so, and goes on with the rest of the round.
 */
final class AgentRoundTest extends KernelTestCase
{
    public function testARoundGoesOnWhenTheRegistryCantBeRead(): void
    {
        $container = self::getContainer();
        // PostgreSQL gone: every query of the registry fails at once
        $container->set('doctrine.dbal.default_connection', DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'quire-database.invalid',
            'dbname' => 'quire_server',
            'user' => 'quire_server',
            'password' => 'none',
            'serverVersion' => '18',
        ]));
        $agent = $container->get(AgentCommand::class);
        self::assertInstanceOf(AgentCommand::class, $agent);
        $output = new BufferedOutput();

        new \ReflectionMethod($agent, 'round')->invoke($agent, new SymfonyStyle(new ArrayInput([]), $output));

        $said = $output->fetch();

        foreach (['Links', 'Front door', 'Git', 'Notifications'] as $step) {
            self::assertMatchesRegularExpression('/^'.$step.': .*quire-database\.invalid/m', $said, $step.' says it failed, and the round went on.');
        }
    }
}
