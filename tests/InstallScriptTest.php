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

    public function testADownloadIsTriedAgainForAMinuteOverHttpsOnly(): void
    {
        // A curl that says how it was called
        $stubs = sys_get_temp_dir().'/install-script-'.bin2hex(random_bytes(4));
        mkdir($stubs);
        file_put_contents($stubs.'/curl', "#!/bin/sh\necho \"$*\"\n");
        chmod($stubs.'/curl', 0o755);

        try {
            $process = new Process(['sh', '-c', $this->functions('get')."\nget -o /dev/null https://github.com/quirecms/server/releases/latest"], null, ['PATH' => $stubs.':'.getenv('PATH')]);
            $process->mustRun();
        } finally {
            unlink($stubs.'/curl');
            rmdir($stubs);
        }

        // GitHub answered 504 to every download for a minute at a time on 2026-11-07: curl's own 3
        // tries took 7 s, and the install failed.
        self::assertStringContainsString('--retry 6 --retry-delay 10', $process->getOutput());
        self::assertStringContainsString('--proto =https --tlsv1.2', $process->getOutput());
        self::assertStringEndsWith("-o /dev/null https://github.com/quirecms/server/releases/latest\n", $process->getOutput());
    }

    public function testEveryDownloadGoesThroughGet(): void
    {
        $script = (string) file_get_contents(__DIR__.'/../install.sh');
        $others = array_filter(explode("\n", $script), static fn (string $line): bool => 1 === preg_match('/\bcurl\s+-/', $line) && !str_starts_with(ltrim($line), '#') && !str_starts_with($line, 'get() {'));

        self::assertSame([], array_values($others), 'Each download is tried again when GitHub fails for a moment.');
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
