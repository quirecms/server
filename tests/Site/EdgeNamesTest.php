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
    public function testTheNameOfReleasesBefore06IsKeptWhereNoOtherSitesPrefixCoversIt(): void
    {
        self::assertSame(['edge.jane.example.quire.internal', 'jane.example.quire.internal'], SiteFiles::edgeNames('jane.example', ['jane-blog', 'demo']));
        self::assertSame(['edge.jane-blog.example.quire.internal'], SiteFiles::edgeNames('jane-blog.example', ['jane']), 'The gate gives "jane-blog.…" to "jane".');
        self::assertSame(['edge.edge-x.com.quire.internal'], SiteFiles::edgeNames('edge-x.com', ['edge']));
        self::assertSame('jane', SiteFiles::coveredBy('jane-blog.example', ['demo', 'jane']));
        self::assertNull(SiteFiles::coveredBy('jane.example', ['jane']), '"jane-" doesn\'t cover "jane.".');
    }

    public function testADomainIsReadBackFromEitherName(): void
    {
        self::assertSame('jane.example', SiteFiles::hostOfEdgeName('edge.jane.example.quire.internal'));
        self::assertSame('jane.example', SiteFiles::hostOfEdgeName('jane.example.quire.internal'));
        self::assertNull(SiteFiles::hostOfEdgeName('jane-php-1'));
    }

    public function testTheFrontDoorAsksForTheseNames(): void
    {
        $caddyfile = (string) file_get_contents(\dirname(__DIR__, 2).'/front/Caddyfile');

        self::assertStringContainsString('reverse_proxy '.SiteFiles::edgeName('{host}').':80 {host}'.SiteFiles::EDGE_SUFFIX.':80 {', $caddyfile);
    }
}
