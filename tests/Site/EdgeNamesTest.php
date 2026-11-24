<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Site;

use PHPUnit\Framework\TestCase;
use Quire\Server\Site\SiteFiles;

/**
 * A site's names on its link, where the front door finds it.
 */
final class EdgeNamesTest extends TestCase
{
    public function testASitesNameOnItsLinkIsItsDomainUnderEdge(): void
    {
        // No site's prefix ("<project>-") covers it: no project's name has a "."
        self::assertSame('edge.jane-blog.example.quire.internal', SiteFiles::edgeName('jane-blog.example'));
    }

    public function testADomainIsReadBackFromItsNameOrTheOldOne(): void
    {
        // A container made before 0.6 may still have the old one until it's next made
        self::assertSame('jane.example', SiteFiles::hostOfEdgeName('edge.jane.example.quire.internal'));
        self::assertSame('jane.example', SiteFiles::hostOfEdgeName('jane.example.quire.internal'));
        self::assertNull(SiteFiles::hostOfEdgeName('jane-php-1'));
    }

    public function testTheFrontDoorAsksForTheseNames(): void
    {
        $caddyfile = (string) file_get_contents(\dirname(__DIR__, 2).'/front/Caddyfile');

        self::assertStringContainsString('reverse_proxy '.SiteFiles::edgeName('{host}').':80 {', $caddyfile, 'That name only: not the one of releases before 0.6.');
    }
}
