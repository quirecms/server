<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\TestCase;
use Quire\Server\Update\Lock;

final class LockTest extends TestCase
{
    public function testWhatChangesBetweenTwoLocks(): void
    {
        $before = self::lock(['quire/core' => 'v0.1.0', 'symfony/yaml' => 'v8.1.0', 'old/gone' => '1.0.0']);
        $after = self::lock(['quire/core' => 'v0.2.0', 'symfony/yaml' => 'v8.1.0', 'new/added' => '2.0.0']);

        self::assertSame('v0.1.0', $before->quire());
        self::assertSame([
            ['package' => 'new/added', 'from' => null, 'to' => '2.0.0'],
            ['package' => 'old/gone', 'from' => '1.0.0', 'to' => null],
            ['package' => 'quire/core', 'from' => 'v0.1.0', 'to' => 'v0.2.0'],
        ], $before->changesTo($after));
        self::assertSame([], $after->changesTo($after));
    }

    public function testALockThatIsntOneIsRefused(): void
    {
        $this->expectExceptionMessageIsOrContains('has no packages');
        Lock::parse('{"content-hash": "x"}');
    }

    /**
     * @param array<string, string> $versions
     */
    public static function lock(array $versions): Lock
    {
        $packages = [];

        foreach ($versions as $name => $version) {
            $packages[] = ['name' => $name, 'version' => $version];
        }

        return Lock::parse(json_encode(['packages' => $packages, 'packages-dev' => []], \JSON_THROW_ON_ERROR));
    }
}
