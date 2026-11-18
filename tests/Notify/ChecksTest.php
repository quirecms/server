<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Notify;

use Quire\Server\Notify\Checks;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\Update\LockTest;
use Quire\Server\Update\AutomaticUpdates;
use Quire\Server\Update\UpdatePlan;

/**
 * What the nightly checks found, as problems: those of backups off the server only while they're set
 * up, the others (an update waiting for a person) always.
 */
final class ChecksTest extends RegistryTestCase
{
    public function testANightWithAReleaseRepublishedWaitsAndALaterOneForgetsIt(): void
    {
        $checks = new Checks($this->connection, $this->schema);
        $from = LockTest::packages([LockTest::package('symfony/yaml', 'v8.1.0'), LockTest::package('symfony/console', 'v8.1.0')]);
        $republished = LockTest::packages([LockTest::package('symfony/yaml', 'v8.1.0', commit: str_repeat('e', 40)), LockTest::package('symfony/console', 'v8.1.1')]);
        $advisory = ['symfony/console' => [['title' => 'Argument injection', 'cve' => null, 'link' => null, 'severity' => 'high']]];
        $plan = new UpdatePlan(new SiteName('jane'), sys_get_temp_dir(), $from, $republished, '{}', $from->changesTo($republished), [], null, true, $advisory, [], '');

        AutomaticUpdates::noteRepublished($checks, $plan);
        $waiting = $checks->get('updates.republished.jane');
        self::assertNotNull($waiting);
        self::assertTrue($waiting['failing']);
        self::assertStringContainsString('symfony/yaml v8.1.0 was re-published as other code', $waiting['summary']);
        self::assertStringContainsString(', and a security fix for symfony/console waits with it.', $waiting['summary'], 'Fail-closed: what it would fix waits too, and is said.');

        // A later night: the tag came back (or an update took it)
        $clean = LockTest::packages([LockTest::package('symfony/yaml', 'v8.1.0'), LockTest::package('symfony/console', 'v8.1.1')]);
        AutomaticUpdates::noteRepublished($checks, new UpdatePlan(new SiteName('jane'), sys_get_temp_dir(), $from, $clean, '{}', $from->changesTo($clean), [], null, true, [], [], ''));
        self::assertNull($checks->get('updates.republished.jane'));
        self::assertSame([], $checks->problems());
    }

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
