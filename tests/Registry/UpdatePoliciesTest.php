<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Registry;

use Quire\Server\Git\Repository;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\UpdatePolicies;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;

final class UpdatePoliciesTest extends RegistryTestCase
{
    private UpdatePolicies $policies;
    private SiteRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->policies = new UpdatePolicies($this->connection, $this->schema);
        $this->registry = new SiteRegistry($this->connection, $this->schema);

        foreach (['jane', 'blog', 'quiet', 'broken'] as $name) {
            $this->registry->add(new SiteName($name), [new Domain($name.'.example')], Site::SHARED_DATABASE, $name);
            $this->registry->setStatus(new SiteName($name), 'broken' === $name ? Site::FAILED : Site::RUNNING);
        }

        new SiteGit($this->connection, $this->schema)->follow(new SiteName('blog'), new Repository('https://example.com/blog.git', 'main'), true);
        $this->policies->set(new SiteName('quiet'), UpdatePolicies::OFF);
    }

    public function testSecurityUpdatesByDefault(): void
    {
        self::assertSame(UpdatePolicies::SECURITY, $this->policies->of(new SiteName('jane')));
        $this->policies->set(new SiteName('jane'), UpdatePolicies::ALL);
        self::assertSame(UpdatePolicies::ALL, $this->policies->of(new SiteName('jane')));
    }

    public function testOnlyTheThreePolicies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policies->set(new SiteName('jane'), 'sometimes');
    }

    public function testOnlyASite(): void
    {
        $this->expectException(\DomainException::class);
        $this->policies->set(new SiteName('nobody'), UpdatePolicies::ALL);
    }

    public function testRunningSitesTheServerUpdatesAreDueOncePerNight(): void
    {
        // Hour 0: tonight's window is open; Git mode, off and failed sites aren't due.
        self::assertSame(['jane'], $this->names($this->policies->due(0)));

        $this->policies->checked(new SiteName('jane'));
        self::assertSame([], $this->policies->due(0), 'Looked at tonight: not again before tomorrow night.');

        $this->connection->executeStatement(\sprintf("UPDATE %s.site SET updates_checked_at = now() - interval '1 day' WHERE name = 'jane'", $this->connection->quoteSingleIdentifier($this->schema)));
        self::assertSame(['jane'], $this->names($this->policies->due(0)), 'Looked at yesterday: due again.');

        // A window that opens later (24: tomorrow at midnight) isn't open yet.
        self::assertSame([], $this->policies->due(24));
    }

    public function testEverySiteTheServerUpdatesWhateverItsPolicy(): void
    {
        self::assertSame(['jane', 'quiet'], $this->names($this->policies->updatable()));
    }

    /**
     * @param list<SiteName> $sites
     *
     * @return list<string>
     */
    private function names(array $sites): array
    {
        return array_map(static fn (SiteName $site): string => $site->value, $sites);
    }
}
