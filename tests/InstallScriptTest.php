<?php

declare(strict_types=1);

namespace Quire\Server\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * install.sh's own functions, run by a real shell: what happens where no one can answer.
 */
final class InstallScriptTest extends TestCase
{
    public function testWithNoTerminalTheAnswerIsNoAndItSaysSo(): void
    {
        // In a session of its own: no controlling terminal, as when it's run from a pipe or a job.
        $process = new Process(['setsid', 'sh', '-c', $this->functions('warn', 'ask')."\nyes=0\nif ask 'Install Docker?'; then echo yes; else echo no; fi"]);
        $process->setInput('');
        $process->run();

        self::assertSame("no\n", $process->getOutput(), 'It goes on, with "no": it doesn\'t end the installer.');
        self::assertStringContainsString('Install Docker? There\'s no terminal to answer on: the answer is no (--yes answers yes).', $process->getErrorOutput());
    }

    public function testYesAnswersYesWithoutAsking(): void
    {
        $process = new Process(['setsid', 'sh', '-c', $this->functions('warn', 'ask')."\nyes=1\nif ask 'Install Docker?'; then echo yes; else echo no; fi"]);
        $process->setInput('');
        $process->run();

        self::assertSame("yes\n", $process->getOutput());
    }

    /**
     * Functions of install.sh, as they are there.
     */
    private function functions(string ...$names): string
    {
        $script = (string) file_get_contents(__DIR__.'/../install.sh');
        $functions = [];

        foreach ($names as $name) {
            self::assertSame(1, preg_match('/^'.$name.'\(\) \{.*?^\}$|^'.$name.'\(\) \{[^\n]*\}$/ms', $script, $match), $name.'() is in install.sh.');
            $functions[] = $match[0];
        }

        return implode("\n", $functions);
    }
}
