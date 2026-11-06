<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Notify;

use Quire\Server\Notify\Problem;
use Quire\Server\Notify\Problems;
use Quire\Server\Tests\Registry\RegistryTestCase;

final class ProblemsTest extends RegistryTestCase
{
    private Problems $problems;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->problems = new Problems($this->connection, $this->schema);
    }

    public function testAProblemIsToldOnceWhenItStartsAndOnceWhenItsOver(): void
    {
        $this->problems->sync([$this->disk()]);
        self::assertSame(['disk'], $this->keys($this->problems->due()['started']));

        $this->problems->told(['disk'], []);
        $this->problems->sync([$this->disk()]);
        self::assertSame(['started' => [], 'reminded' => [], 'over' => []], $this->problems->due(), 'Still there the next round: nothing to tell.');

        $this->problems->sync([]);
        self::assertSame(['disk'], $this->keys($this->problems->due()['over']));
        self::assertSame([], $this->problems->open());

        $this->problems->told([], ['disk']);
        self::assertSame(['started' => [], 'reminded' => [], 'over' => []], $this->problems->due(), 'Told it\'s over: forgotten.');
    }

    public function testAProblemStillThereAWeekLaterIsToldAgain(): void
    {
        $this->problems->sync([$this->disk()]);
        $this->problems->told(['disk'], []);
        $this->connection->executeStatement(\sprintf("UPDATE %s.problem SET notified_at = now() - interval '8 days'", $this->connection->quoteSingleIdentifier($this->schema)));

        self::assertSame(['disk'], $this->keys($this->problems->due()['reminded']));
    }

    public function testAProblemOverBeforeAnyoneWasToldIsJustForgotten(): void
    {
        $this->problems->sync([$this->disk()]);
        $this->problems->sync([]);
        $this->problems->forgetUntold();

        self::assertSame(['started' => [], 'reminded' => [], 'over' => []], $this->problems->due());
    }

    public function testOneThatComesBackBeforeItsEndWasToldIsntOver(): void
    {
        $this->problems->sync([$this->disk()]);
        $this->problems->told(['disk'], []);
        $this->problems->sync([]);
        $this->problems->sync([$this->disk()]);

        self::assertSame(['started' => [], 'reminded' => [], 'over' => []], $this->problems->due(), 'No "fixed" then "new" for a problem that flapped.');
        self::assertSame(['disk'], $this->keys($this->problems->open()));
    }

    public function testANewerFailureOfTheSameKindStartsAgain(): void
    {
        $this->problems->sync([$this->failure('a1b2c3d4-0000-4000-8000-000000000001')]);
        $this->problems->told(['operation:site.update:jane'], []);
        $this->problems->dismiss('operation:site.update:jane');
        $this->problems->sync([$this->failure('a1b2c3d4-0000-4000-8000-000000000001')]);
        self::assertSame([], $this->problems->open(), 'Dismissed: not shown while it\'s the same failure.');

        $this->problems->sync([$this->failure('a1b2c3d4-0000-4000-8000-000000000002')]);

        self::assertSame(['operation:site.update:jane'], $this->keys($this->problems->due()['started']), 'Another night\'s failure is news.');
        self::assertSame('a1b2c3d4-0000-4000-8000-000000000002', $this->problems->open()[0]['problem']->operation);
    }

    public function testADismissedProblemThatsOverIsntToldAsFixed(): void
    {
        $this->problems->sync([$this->disk()]);
        $this->problems->told(['disk'], []);
        self::assertTrue($this->problems->dismiss('disk'));
        self::assertFalse($this->problems->dismiss('no-such'));
        $this->problems->sync([]);
        $this->problems->forgetUntold();

        self::assertSame(['started' => [], 'reminded' => [], 'over' => []], $this->problems->due());
    }

    public function testTheNotifiersOwnProblemIsLeftToIt(): void
    {
        $this->problems->raise(new Problem(Problems::NOTIFY, 'Notifications couldn\'t be sent.', 'Check them.'));
        $this->problems->sync([$this->disk()]);

        self::assertSame([Problems::NOTIFY, 'disk'], $this->keys($this->problems->open()), 'A round of the watch doesn\'t end it.');

        $this->problems->clear(Problems::NOTIFY);
        self::assertSame(['disk'], $this->keys($this->problems->open()));

        $this->expectException(\LogicException::class);
        $this->problems->sync([new Problem(Problems::NOTIFY, 'x', 'y')]);
    }

    public function testMemoryRunningOutIsToldOnlyOnceItHasLastedTenMinutes(): void
    {
        $memory = new Problem('memory', 'Memory is running out.', 'Add memory.');
        $this->problems->sync([$memory]);
        self::assertSame([], $this->problems->due()['started'], 'A build\'s peak passes by itself.');

        $this->problems->sync([]);
        $this->problems->forgetUntold();
        self::assertSame(['started' => [], 'reminded' => [], 'over' => []], $this->problems->due(), 'Over sooner: never told.');

        $this->problems->sync([$memory]);
        $this->connection->executeStatement(\sprintf("UPDATE %s.problem SET since = now() - interval '11 minutes'", $this->connection->quoteSingleIdentifier($this->schema)));
        self::assertSame(['memory'], $this->keys($this->problems->due()['started']), 'Still there 10 minutes on: told.');
    }

    public function testKeysAreChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Problem("disk'; DROP TABLE problem; --", 'x', 'y');
    }

    private function disk(): Problem
    {
        return new Problem('disk', 'The disk is almost full.', 'Make room.');
    }

    private function failure(string $operation): Problem
    {
        return new Problem('operation:site.update:jane', 'The automatic update of jane failed.', 'See it.', 'jane', $operation);
    }

    /**
     * @param list<array{problem: Problem, since: \DateTimeImmutable, notified_at: ?\DateTimeImmutable, resolved_at: ?\DateTimeImmutable, dismissed: bool}> $stored
     *
     * @return list<string>
     */
    private function keys(array $stored): array
    {
        return array_map(static fn (array $one): string => $one['problem']->key, $stored);
    }
}
