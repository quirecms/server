<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\TestCase;
use Quire\Server\Update\SiteUpdater;
use Symfony\Component\Process\Process;

/**
 * The check after a site starts, run as it runs in the site's container: against a real server (PHP's
 * own) whose pages answer 503 now and then, as a site that has just started can under load.
 */
final class SiteCheckTest extends TestCase
{
    private ?Process $server = null;
    private string $id = '';

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop(0);
        $files = glob(sys_get_temp_dir().'/quire-flaky-'.$this->id.'-*');

        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
    }

    public function testABusyMomentIsntAFailure(): void
    {
        self::assertSame(0, $this->check('ok'), 'Each page answered rightly once, if never all at once: the release stays.');
    }

    public function testAPageThatNeverAnswersIsOne(): void
    {
        self::assertSame(1, $this->check('broken'), 'The sign-in page never did: rolled back.');
    }

    private function check(string $pages): int
    {
        $this->id = bin2hex(random_bytes(6));
        $port = random_int(20000, 40000);
        $this->server = new Process(['php', '-S', '127.0.0.1:'.$port, __DIR__.'/fixtures/flaky-site.php'], env: ['QUIRE_TEST_ID' => $this->id, 'QUIRE_TEST_PAGES' => $pages]);
        $this->server->start();
        usleep(300_000);

        $check = new Process(['php', '-r', SiteUpdater::checkScript('jane.example', 'http://127.0.0.1:'.$port, seconds: 4, pause: 1)]);
        $check->run();

        return (int) $check->getExitCode();
    }
}
