<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Process;

use PHPUnit\Framework\TestCase;
use Quire\Server\Process\SymfonyProcessRunner;

/**
 * A file the agent streams to a program, e.g. a backup into quire:restore, with real programs: only
 * the agent's own file, never one a link points at.
 */
final class StreamedFileTest extends TestCase
{
    public function testAFileIsStreamed(): void
    {
        $file = sys_get_temp_dir().'/quire-streamed-'.bin2hex(random_bytes(6));
        file_put_contents($file, 'a backup');

        try {
            self::assertSame('a backup', new SymfonyProcessRunner()->run(['cat'], timeout: 10, inputFile: $file)->output);
        } finally {
            unlink($file);
        }
    }

    public function testALinkIsNeverStreamed(): void
    {
        $link = sys_get_temp_dir().'/quire-streamed-'.bin2hex(random_bytes(6));
        symlink('/etc/hostname', $link);

        try {
            new SymfonyProcessRunner()->run(['cat'], timeout: 10, inputFile: $link);
            self::fail('A link was read.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('is a link', $e->getMessage());
        } finally {
            unlink($link);
        }
    }
}
