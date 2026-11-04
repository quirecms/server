<?php

declare(strict_types=1);

namespace Quire\Server\Console;

/**
 * A time as the commands show it (NOVICE-07, OWNER-11): to the minute, in UTC, and saying so. Not
 * "2026-10-29 14:54:43.908811+00" or "2026-10-29T14:54:03.052065248Z".
 */
final class Time
{
    public static function utc(string|\DateTimeInterface $time): string
    {
        try {
            $time = $time instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($time) : new \DateTimeImmutable($time);
        } catch (\Exception) {
            return \is_string($time) ? $time : '';
        }

        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';
    }
}
