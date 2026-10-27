<?php

declare(strict_types=1);

namespace Quire\Server\Tests\FrontDoor;

use PHPUnit\Framework\TestCase;
use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Site\Domain;
use Quire\Server\Site\SiteName;
use Symfony\Component\Filesystem\Filesystem;

final class DomainDirectoryTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quire-domains-'.bin2hex(random_bytes(6)).'/domains';
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove(\dirname($this->directory));
    }

    public function testAFilePerAllowedDomainNamingItsSite(): void
    {
        $front = new DomainDirectory($this->directory);
        $front->allow(new Domain('jane.example'), new SiteName('jane'));
        $front->allow(new Domain('Müller.example'), new SiteName('kim'));

        self::assertSame(['jane.example' => 'jane', 'xn--mller-kva.example' => 'kim'], $front->all());
        self::assertSame("jane\n", file_get_contents($this->directory.'/jane.example'));
        self::assertSame('0644', substr(\sprintf('%o', fileperms($this->directory.'/jane.example')), -4), 'Readable by the front door.');
        self::assertSame([], glob($this->directory.'/*.tmp'), 'Written atomically: no leftovers.');

        $front->revoke(new Domain('jane.example'));
        $front->revoke(new Domain('jane.example'));
        self::assertSame(['xn--mller-kva.example' => 'kim'], $front->all());
    }

    public function testReconcilingMakesItMatchTheRegistry(): void
    {
        $front = new DomainDirectory($this->directory);
        $front->allow(new Domain('stale.example'), new SiteName('gone'));
        $front->allow(new Domain('jane.example'), new SiteName('wrong'));
        file_put_contents($this->directory.'/not a domain', 'x');

        $changes = $front->reconcile(['jane.example' => 'jane', 'kim.example' => 'kim']);

        self::assertSame(['jane.example' => 'jane', 'kim.example' => 'kim'], $front->all());
        self::assertSame(['jane.example', 'kim.example'], $changes['added']);
        self::assertSame(['not a domain', 'stale.example'], $changes['removed'], 'Whatever else is there goes.');
        self::assertSame(['added' => [], 'removed' => []], $front->reconcile(['jane.example' => 'jane', 'kim.example' => 'kim']), 'Nothing twice.');
    }
}
