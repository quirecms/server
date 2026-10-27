<?php

declare(strict_types=1);

namespace Quire\Server\Console;

/**
 * Who asked, on the command line: the host's user, as quire-server passes it (QUIRE_SERVER_REQUESTED_BY,
 * from sudo's SUDO_USER or the user's own name). Only for the record: whoever can run quire-server
 * is root on the machine already.
 */
final class Requester
{
    public const string CHANNEL = 'cli';

    public static function name(): string
    {
        $name = getenv('QUIRE_SERVER_REQUESTED_BY');

        return \is_string($name) && 1 === preg_match('/^[a-z_][a-z0-9_.-]{0,31}\z/i', $name) ? $name : 'root';
    }
}
