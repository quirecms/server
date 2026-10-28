<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Console;

use PHPUnit\Framework\TestCase;
use Quire\Server\Console\VersionCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class VersionCommandTest extends TestCase
{
    public function testARelease(): void
    {
        self::assertSame("Quire Server 0.1.0\nAgent: ghcr.io/quirecms/server@sha256:abc\n", $this->display('0.1.0', 'ghcr.io/quirecms/server@sha256:abc'));
    }

    public function testACheckout(): void
    {
        self::assertSame("Quire Server dev\nAgent: built from a checkout\n", $this->display('dev', ''));
    }

    private function display(string $version, string $image): string
    {
        $application = new Application();
        $application->addCommand(new VersionCommand($version, $image));
        $tester = new CommandTester($application->find('server:version'));
        $tester->execute([]);

        return $tester->getDisplay();
    }
}
