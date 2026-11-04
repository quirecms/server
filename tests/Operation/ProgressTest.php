<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Operation;

use PHPUnit\Framework\TestCase;
use Quire\Server\Operation\Progress;

/**
 * A long build's heartbeat (M-20, NOVICE-01): every half minute, how long and at which step, also
 * while the build says nothing (the runner's tick).
 */
final class ProgressTest extends TestCase
{
    public function testTheOwnerHearsHowLongAndAtWhichStep(): void
    {
        $now = 1000;
        $log = '';
        /** @var \ArrayObject<int, string> $said */
        $said = new \ArrayObject();
        $chunk = Progress::of(static function (string $text) use (&$log): void {
            $log .= $text;
        }, static function (string $line) use ($said): void {
            $said[] = $line;
        }, now: static function () use (&$now): int {
            return $now;
        });

        $chunk("#5 [php 2/9] RUN apk add icu\n");
        $now += 10;
        $chunk("#12 [php 5/9] RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress\n#12 1.2 Installing dependencies\n");
        self::assertSame([], $said->getArrayCopy(), 'Not before half a minute.');

        $now += 25;
        $chunk('');
        $now += 120;
        $chunk('');

        self::assertSame([
            'Still building (35 s so far, at php 5/9: RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader…)',
            'Still building (2 min so far, at php 5/9: RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader…)',
        ], $said->getArrayCopy());
        self::assertStringContainsString('Installing dependencies', $log, 'The output goes on to the log as it came.');
    }
}
