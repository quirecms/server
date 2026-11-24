<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerUpdate;

use PHPUnit\Framework\TestCase;
use Quire\Server\ServerUpdate\ServerFolder;

/**
 * The end-to-end test rolls a release back as the updater does (tests/e2e/run.sh, release_entries):
 * it leaves what ServerFolder keeps from one release to the next where it is. A copy of that list
 * that falls behind removes what a running container mounts, as the front door's domains once were.
 */
final class EndToEndRollbackTest extends TestCase
{
    public function testItKeepsWhatTheUpdaterKeeps(): void
    {
        $script = (string) file_get_contents(__DIR__.'/../e2e/run.sh');
        self::assertSame(1, preg_match('/^release_entries="(.*)"$/m', $script, $line), 'run.sh names the release\'s entries once.');
        preg_match_all("/! -name '?([^' ]+)'?/", $line[1], $names);

        $kept = [...ServerFolder::KEPT, 'server.env.*', ServerFolder::UPDATE_PREFIX.'*'];
        sort($kept);
        $left = $names[1];
        sort($left);

        self::assertSame($kept, $left);
    }
}
