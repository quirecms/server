<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Notify;

use Quire\Server\Notify\Checks;
use Quire\Server\Tests\Registry\RegistryTestCase;

/**
 * What the nightly checks found, as problems: those of backups off the server only while they're set
 * up, the others (an update waiting for a person) always.
 */
final class ChecksTest extends RegistryTestCase
{
    public function testWhatFailsIsAProblem(): void
    {
        $checks = new Checks($this->connection, $this->schema);
        $checks->record('backups.protection', true, 'Backups off the server aren\'t protected.');
        $checks->record('updates.republished.jane', true, 'The automatic update of jane waits for you.', 'Look at the commits.');
        $checks->record('updates.republished.kim', false, 'Fine.');

        self::assertSame(['check:backups.protection', 'check:updates.republished.jane'], array_map(static fn (\Quire\Server\Notify\Problem $problem): string => $problem->key, $checks->problems()));
        self::assertSame(['check:updates.republished.jane'], array_map(static fn (\Quire\Server\Notify\Problem $problem): string => $problem->key, $checks->problems(backups: false)), 'Without backups set up, theirs aren\'t problems; an update waiting still is.');
    }
}
