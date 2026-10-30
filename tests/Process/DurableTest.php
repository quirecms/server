<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Process;

use PHPUnit\Framework\TestCase;
use Quire\Server\Process\Durable;

/**
 * Putting a file the server wrote on the disk: a file that isn't there is an error, not a silence.
 */
final class DurableTest extends TestCase
{
    public function testAFileIsPutOnTheDisk(): void
    {
        $file = sys_get_temp_dir().'/quire-durable-'.bin2hex(random_bytes(6));
        file_put_contents($file, 'server.env');

        try {
            Durable::sync($file);
            self::assertSame('server.env', file_get_contents($file), 'As it was.');
        } finally {
            unlink($file);
        }
    }

    public function testAFileThatIsntThereIsAnError(): void
    {
        $this->expectExceptionMessageIsOrContains('can\'t be read to put it on the disk');
        Durable::sync(sys_get_temp_dir().'/quire-durable-missing-'.bin2hex(random_bytes(6)));
    }
}
