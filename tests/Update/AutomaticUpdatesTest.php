<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Registry\UpdatePolicies;
use Quire\Server\Site\SiteName;
use Quire\Server\Update\AutomaticUpdates;
use Quire\Server\Update\SkeletonFiles;
use Quire\Server\Update\UpdatePlan;

/**
 * When a night's update is made without a person, and when it waits for one. (Making it is the
 * manual update's path: see SiteUpdaterTest, and the end-to-end test.).
 */
final class AutomaticUpdatesTest extends TestCase
{
    private const array VULNERABLE = ['symfony/yaml' => [['title' => 'Billion laughs', 'cve' => 'CVE-2026-1111', 'link' => null, 'severity' => 'high']]];
    private const array VOUCHED = ['vouched' => true, 'blocked' => [], 'notes' => [], 'security' => []];

    /**
     * @return iterable<string, array{UpdatePlan, string, array{vouched: bool, blocked: list<string>, notes: list<string>, security: list<string>}|null, ?string}>
     */
    public static function cases(): iterable
    {
        $fix = self::plan(['symfony/yaml' => ['v8.1.0', 'v8.1.3']], before: self::VULNERABLE, after: []);
        $noFix = self::plan(['symfony/yaml' => ['v8.1.0', 'v8.1.1']], before: self::VULNERABLE, after: self::VULNERABLE);
        $plain = self::plan(['symfony/console' => ['v8.1.0', 'v8.1.1']]);
        $quire = self::plan(['quire/core' => ['v0.1.1', 'v0.1.2']], before: self::VULNERABLE, after: []);
        $edited = self::plan(['quire/core' => ['v0.1.1', 'v0.2.0']], skeleton: [['path' => 'Dockerfile', 'action' => SkeletonFiles::CONFLICT]]);

        yield 'security: a fix' => [$fix, UpdatePolicies::SECURITY, self::VOUCHED, null];
        yield 'security: an update that doesn\'t fix it' => [$noFix, UpdatePolicies::SECURITY, self::VOUCHED, 'no update fixes'];
        yield 'security: no vulnerability' => [$plain, UpdatePolicies::SECURITY, self::VOUCHED, 'no update fixes'];
        yield 'all: any update' => [$plain, UpdatePolicies::ALL, self::VOUCHED, null];
        yield 'off: never' => [$fix, UpdatePolicies::OFF, self::VOUCHED, 'are off'];
        yield 'nothing to update' => [self::plan([]), UpdatePolicies::ALL, self::VOUCHED, 'nothing to update'];
        yield 'edited skeleton files' => [$edited, UpdatePolicies::ALL, self::VOUCHED, 'edited files of the skeleton'];
        yield 'a new Quire the feed vouches for' => [$quire, UpdatePolicies::SECURITY, self::VOUCHED, null];
        yield 'a new Quire the feed doesn\'t vouch for' => [$quire, UpdatePolicies::SECURITY, ['vouched' => false, 'blocked' => [], 'notes' => [], 'security' => []], 'isn\'t vouched for'];
        yield 'a new Quire this server can\'t run' => [$quire, UpdatePolicies::ALL, ['vouched' => false, 'blocked' => ['Quire 0.1.2 keeps server contract 2.'], 'notes' => [], 'security' => []], 'server contract 2'];
        yield 'a site not in the registry' => [$plain, UpdatePolicies::ALL, null, 'isn\'t in the registry'];
    }

    /**
     * @param array{vouched: bool, blocked: list<string>, notes: list<string>, security: list<string>}|null $check
     */
    #[DataProvider('cases')]
    public function testWhenAnUpdateIsMadeByItself(UpdatePlan $plan, string $policy, ?array $check, ?string $skipped): void
    {
        $reason = AutomaticUpdates::reasonToSkip($plan, $policy, $check);

        if (null === $skipped) {
            self::assertNull($reason);
        } else {
            self::assertStringContainsString($skipped, (string) $reason);
        }
    }

    /**
     * @param array<string, array{string, string}>                                                      $changes  Package => [from, to]
     * @param array<string, list<array{title: string, cve: ?string, link: ?string, severity: ?string}>> $before
     * @param array<string, list<array{title: string, cve: ?string, link: ?string, severity: ?string}>> $after
     * @param list<array{path: string, action: string}>                                                 $skeleton
     */
    private static function plan(array $changes, array $before = [], array $after = [], array $skeleton = []): UpdatePlan
    {
        $from = ['quire/core' => 'v0.1.1'];
        $to = ['quire/core' => 'v0.1.1'];
        $packages = [];

        foreach ($changes as $package => [$old, $new]) {
            $from[$package] = $old;
            $to[$package] = $new;
            $packages[] = ['package' => $package, 'from' => $old, 'to' => $new];
        }

        return new UpdatePlan(new SiteName('jane'), sys_get_temp_dir(), LockTest::lock($from), LockTest::lock($to), '{}', $packages, $skeleton, null, true, $before, $after, '');
    }

    public function testTheHourIsAnHourOrOff(): void
    {
        self::assertSame(3, AutomaticUpdates::parseHour('3'));
        self::assertSame(0, AutomaticUpdates::parseHour('0'));
        self::assertSame(23, AutomaticUpdates::parseHour('23'));
        self::assertNull(AutomaticUpdates::parseHour('off'));

        foreach (['24', '-1', '03', ' 3', "3\n", 'never', ''] as $hour) {
            try {
                AutomaticUpdates::parseHour($hour);
                self::fail('"'.$hour.'" was taken for an hour.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('QUIRE_SERVER_UPDATE_HOUR', $e->getMessage());
            }
        }
    }
}
