<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Doctrine\DBAL\Connection;
use Quire\Server\Release\QuireFeed;
use Quire\Server\Site\Site;

/**
 * What Quire's signed release feed says of an update that changes a site's Quire: whether this server
 * can run that release (the server contract it needs, the PostgreSQL it needs for sites on the
 * server's), the security fixes it brings and what to do when updating. An update that keeps Quire's
 * version needs none of it.
 */
final readonly class ReleaseCheck
{
    /** The newest server contract this server keeps (Quire\Runtime\ServerContract::VERSION) */
    public const int CONTRACT = 1;

    public function __construct(
        private QuireFeed $feed,
        private Connection $admin,
    ) {
    }

    /**
     * "vouched": the feed lists the release, and this server meets what it requires; "blocked": why
     * this server can't run it; "notes": what to do when updating; "security": what it fixes.
     *
     * @return array{vouched: bool, blocked: list<string>, notes: list<string>, security: list<string>}
     */
    public function check(UpdatePlan $plan, Site $site): array
    {
        $to = $plan->quireTo();

        if (null === $to || $to === $plan->quireFrom()) {
            return ['vouched' => true, 'blocked' => [], 'notes' => [], 'security' => []];
        }

        try {
            $feed = $this->feed->read();
        } catch (\Throwable $e) {
            return ['vouched' => false, 'blocked' => [], 'notes' => ['Quire\'s release feed couldn\'t be read ('.$e->getMessage().'): what Quire '.$to.' requires isn\'t checked.'], 'security' => []];
        }

        $release = $feed?->release(ltrim($to, 'v'));

        if (!$feed instanceof \Quire\Server\Release\Feed || null === $release) {
            return ['vouched' => false, 'blocked' => [], 'notes' => ['Quire '.$to.' isn\'t in Quire\'s release feed: what it requires isn\'t checked.'], 'security' => []];
        }

        $blocked = [];
        $contract = $release['requires']['server_contract'];

        if (null !== $contract && $contract > self::CONTRACT) {
            $blocked[] = \sprintf('Quire %s keeps server contract %d; this server knows up to %d: update the server first ("quire-server update").', $to, $contract, self::CONTRACT);
        }

        $postgresql = $release['requires']['postgresql'];

        if (null !== $postgresql && Site::SHARED_DATABASE === $site->databaseMode && $postgresql > $this->postgresql()) {
            $blocked[] = \sprintf('Quire %s needs PostgreSQL %d; the server\'s is %d.', $to, $postgresql, $this->postgresql());
        }

        $notes = [];
        $security = [];

        foreach ($feed->between(null === $plan->quireFrom() ? null : ltrim($plan->quireFrom(), 'v'), ltrim($to, 'v')) as $between) {
            if (null !== $between['upgrade']) {
                $notes[] = \sprintf('Quire %s: %s', $between['version'], $between['upgrade']);
            }

            foreach ($between['security'] as $advisory) {
                $security[] = \sprintf('Quire %s fixes %s (%s, %s): %s', $between['version'], $advisory['id'], $advisory['severity'], $advisory['package'], $advisory['summary']);
            }
        }

        return ['vouched' => [] === $blocked, 'blocked' => $blocked, 'notes' => $notes, 'security' => $security];
    }

    /**
     * The server's PostgreSQL major version.
     */
    private function postgresql(): int
    {
        $number = $this->admin->fetchOne('SHOW server_version_num');

        return intdiv(is_numeric($number) ? (int) $number : 0, 10000);
    }
}
