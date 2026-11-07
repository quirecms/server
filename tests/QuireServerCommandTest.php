<?php

declare(strict_types=1);

namespace Quire\Server\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * bin/quire-server itself, with stand-ins for docker, id and sleep: how it starts the updater's
 * commands (update, backups:setup, database:upgrade), one at a time.
 */
final class QuireServerCommandTest extends TestCase
{
    private string $root;
    private string $stubs;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-server-command-'.bin2hex(random_bytes(4));
        $this->stubs = $this->root.'/stubs';
        $filesystem = new Filesystem();
        $filesystem->mirror(__DIR__.'/../bin', $this->root.'/server/bin');
        $filesystem->dumpFile($this->root.'/server/server.env', '');
        // root, as quire-server needs
        $this->stub('id', 'case "$1" in -u) echo 0 ;; *) echo root ;; esac');
        $this->stub('sleep', 'echo sleep >> "$STUBS/calls"');
        // The updater's container: its states, one per "docker inspect" of it, from a file (none when it's used up)
        $this->stub('docker', <<<'SH'
            echo "docker $*" >> "$STUBS/calls"
            case "$*" in
                "inspect --format {{.State.Status}} quire-server-updater")
                    state=$(head -n 1 "$STUBS/states" 2>/dev/null)
                    # One that isn't there: Docker prints an empty line, and fails
                    [ -n "$state" ] || { echo; exit 1; }
                    [ "$(wc -l < "$STUBS/states")" -gt 1 ] && sed -i 1d "$STUBS/states"
                    [ "$state" = none ] && { echo; exit 1; }
                    echo "$state" ;;
                "inspect --format {{range .Config.Cmd}}{{.}} {{end}} quire-server-updater") echo 'bin/console server:database:upgrade --to=19 ' ;;
                "rm --force quire-server-updater") echo none > "$STUBS/states" ;;
                # Listed (also when it has ended) while it exists, as Docker does
                "ps -a --format {{.Names}}") state=$(head -n 1 "$STUBS/states" 2>/dev/null); [ -n "$state" ] && [ "$state" != none ] && echo quire-server-updater ;;
                ps*) ;;
                compose*) echo ran ;;
            esac
            SH);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testWithNoUpdaterTheCommandRuns(): void
    {
        $run = $this->backupsSetup(states: []);

        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());
        self::assertStringContainsString('ran', $run->getOutput());
    }

    public function testAnUpdaterThatEndedAndIsBeingRemovedIsWaitedFor(): void
    {
        // As "compose run --rm" leaves it a moment after the command before ended
        $run = $this->backupsSetup(states: ['exited', 'removing', 'none']);

        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());
        self::assertStringContainsString('ran', $run->getOutput());
        self::assertSame(2, substr_count($this->calls(), "sleep\n"));
        self::assertStringNotContainsString('docker rm', $this->calls(), 'Docker removes it by itself.');
    }

    public function testARunningUpdaterIsRefusedNamingItsCommand(): void
    {
        $run = $this->backupsSetup(states: ['running']);

        self::assertFalse($run->isSuccessful());
        self::assertStringContainsString('quire-server database:upgrade runs already', $run->getErrorOutput());
        self::assertStringNotContainsString('docker compose', $this->calls(), 'Nothing is started.');
    }

    public function testAnUpdaterThatEndedAndStaysIsRemovedAfterHalfAMinute(): void
    {
        $run = $this->backupsSetup(states: ['exited']);

        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());
        self::assertStringContainsString('ran', $run->getOutput());
        self::assertSame(30, substr_count($this->calls(), "sleep\n"));
        self::assertStringContainsString('docker rm --force quire-server-updater', $this->calls());
    }

    public function testAnEndedUpdaterThatCantBeRemovedIsSaid(): void
    {
        $run = $this->backupsSetup(states: ['dead'], removable: false);

        self::assertFalse($run->isSuccessful());
        self::assertStringContainsString('can\'t be removed', $run->getErrorOutput());
        self::assertStringNotContainsString('docker compose', $this->calls());
    }

    /**
     * quire-server backups:setup, with the updater's container in these states, one per look.
     *
     * @param list<string> $states
     */
    private function backupsSetup(array $states, bool $removable = true): Process
    {
        file_put_contents($this->stubs.'/states', [] === $states ? '' : implode("\n", $states)."\n");

        if (!$removable) {
            // "docker rm" leaves it there
            $docker = (string) file_get_contents($this->stubs.'/docker');
            file_put_contents($this->stubs.'/docker', str_replace('"rm --force quire-server-updater") echo none > "$STUBS/states" ;;', '"rm --force quire-server-updater") exit 1 ;;', $docker));
        }

        $process = new Process(['sh', $this->root.'/server/bin/quire-server', 'backups:setup'], null, [
            'PATH' => $this->stubs.':'.getenv('PATH'),
            'STUBS' => $this->stubs,
            'QUIRE_SERVER_ROOT' => $this->root,
        ], null, 60);
        $process->run();

        return $process;
    }

    private function calls(): string
    {
        return (string) @file_get_contents($this->stubs.'/calls');
    }

    private function stub(string $name, string $body): void
    {
        new Filesystem()->dumpFile($this->stubs.'/'.$name, "#!/bin/sh\n".$body."\n");
        chmod($this->stubs.'/'.$name, 0o755);
    }
}
