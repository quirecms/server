<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Console;

use PHPUnit\Framework\TestCase;
use Quire\Server\Console\KeyCommand;
use Quire\Server\Site\SiteName;

/**
 * A deploy key says which server and site it's for, where the owner adds it (a repository's keys).
 */
final class KeyCommandTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        putenv('QUIRE_SERVER_NAME');
        putenv('QUIRE_SERVER_HOST_NAME');
    }

    public function testTheKeyIsNamedAfterTheServerAndTheSite(): void
    {
        putenv('QUIRE_SERVER_HOST_NAME=fra1-web-01.example');
        self::assertSame('fra1-web-01.example:poposki-technology', KeyCommand::comment(new SiteName('poposki-technology')), 'The machine\'s host name, not the container\'s.');

        putenv('QUIRE_SERVER_NAME=Web server #1');
        self::assertSame('Web-server-1:poposki-technology', KeyCommand::comment(new SiteName('poposki-technology')), 'Its name when it has one, in plain characters.');

        putenv('QUIRE_SERVER_NAME');
        putenv('QUIRE_SERVER_HOST_NAME');
        self::assertSame('quire-server:blog', KeyCommand::comment(new SiteName('blog')));
    }
}
