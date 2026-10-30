<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerDatabase;

use PHPUnit\Framework\TestCase;
use Quire\Server\ServerDatabase\DatabaseUpgrade;
use Symfony\Component\Filesystem\Filesystem;

final class DatabaseUpgradeTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-server-upgrades-'.bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testWrittenDownAndReadBack(): void
    {
        $directory = DatabaseUpgrade::directory($this->root).'/20261029-180000-000000';
        $upgrade = new DatabaseUpgrade($directory, 18, 19, DatabaseUpgrade::COPYING, [], new \DateTimeImmutable('2026-10-29 18:00'));
        $upgrade->save();
        $upgrade = $upgrade->with(DatabaseUpgrade::COPYING, ['abc123def456']);

        $read = DatabaseUpgrade::latest(DatabaseUpgrade::directory($this->root));

        self::assertInstanceOf(DatabaseUpgrade::class, $read);
        self::assertSame([18, 19, 'copying', ['abc123def456']], [$read->from, $read->to, $read->state, $read->stopped]);
        self::assertTrue($read->unfinished());
        self::assertSame(0o700, fileperms($directory) & 0o777);
        self::assertSame(0o600, fileperms($directory.'/upgrade.json') & 0o777);
        self::assertSame($upgrade->changedAt?->format(\DATE_ATOM), $read->changedAt?->format(\DATE_ATOM));
    }

    public function testTheNewestCounts(): void
    {
        $upgrades = DatabaseUpgrade::directory($this->root);
        new DatabaseUpgrade($upgrades.'/20261020-180000-000000', 17, 18, DatabaseUpgrade::DONE, [], new \DateTimeImmutable('2026-10-20'))->save();
        new DatabaseUpgrade($upgrades.'/20261029-180000-000000', 18, 19, DatabaseUpgrade::FAILED, [], new \DateTimeImmutable('2026-10-29'))->save();
        // Not an upgrade: skipped
        new Filesystem()->dumpFile($upgrades.'/20261030-180000-000000/upgrade.json', '{"from": "18"}');

        self::assertSame(19, DatabaseUpgrade::latest($upgrades)?->to);
        self::assertNull(DatabaseUpgrade::latest($this->root.'/nowhere'));
    }

    public function testUndoableForAWeekOnceDone(): void
    {
        $upgrade = new DatabaseUpgrade('/x', 18, 19, DatabaseUpgrade::DONE, [], new \DateTimeImmutable('2026-10-29 18:00'));

        self::assertTrue($upgrade->undoable(new \DateTimeImmutable('2026-11-05 17:59')));
        self::assertFalse($upgrade->undoable(new \DateTimeImmutable('2026-11-05 18:01')));
        self::assertFalse(new DatabaseUpgrade('/x', 18, 19, DatabaseUpgrade::UNDONE, [], new \DateTimeImmutable('2026-10-29 18:00'))->undoable(new \DateTimeImmutable('2026-10-30')));
        self::assertFalse($upgrade->unfinished());
    }

    public function testWhetherTheMajorChangedSinceAMoment(): void
    {
        $upgrades = DatabaseUpgrade::directory($this->root);
        $upgrade = new DatabaseUpgrade($upgrades.'/20261029-180000-000000', 18, 19, DatabaseUpgrade::DONE, [], new \DateTimeImmutable('2026-10-29 18:00'), new \DateTimeImmutable('2026-10-29 18:10'));
        $upgrade->save();

        self::assertTrue(DatabaseUpgrade::changedSince($this->root, new \DateTimeImmutable('2026-10-29 17:00')));
        self::assertFalse(DatabaseUpgrade::changedSince($this->root, new \DateTimeImmutable('2026-10-29 18:20')));

        // A failed one changed nothing.
        new DatabaseUpgrade($upgrade->directory, 18, 19, DatabaseUpgrade::FAILED, [], $upgrade->createdAt, $upgrade->changedAt)->save();
        self::assertFalse(DatabaseUpgrade::changedSince($this->root, new \DateTimeImmutable('2026-10-29 17:00')));
        self::assertFalse(DatabaseUpgrade::changedSince($this->root.'/nowhere', new \DateTimeImmutable('2026-10-29 17:00')));
    }
}
