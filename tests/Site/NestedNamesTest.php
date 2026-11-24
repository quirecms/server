<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Site;

use PHPUnit\Framework\TestCase;
use Quire\Server\Site\NestedNames;

/**
 * Site names that nest ("jane" and "jane-shop"): Compose's names of one would be under the other's
 * prefix, so a new site never nests with another.
 */
final class NestedNamesTest extends TestCase
{
    public function testANameAndADashNests(): void
    {
        self::assertSame('jane', NestedNames::with('jane-shop', ['kim', 'jane']));
        self::assertSame('jane-shop', NestedNames::with('jane', ['jane-shop']), 'The other way round too.');
        self::assertSame('jane', NestedNames::with('jane-shop-two', ['jane']), 'However deep.');
    }

    public function testOtherNamesDontNest(): void
    {
        self::assertNull(NestedNames::with('janet', ['jane']), 'No dash: Compose\'s names of janet start with "janet-", not "jane-".');
        self::assertNull(NestedNames::with('jane', ['jane']), 'Itself.');
        self::assertNull(NestedNames::with('shop-jane', ['jane']));
        self::assertNull(NestedNames::with('jane', []));
    }

    public function testTheRefusalSaysWhoseNamesAndWhatToDo(): void
    {
        self::assertSame('"jane-shop" and the site "jane" would nest: jane-shop\'s names would start with "jane-", which are jane\'s. Pick a name that doesn\'t start with another site\'s name and a dash, and that no other site\'s starts with.', NestedNames::refusal('jane-shop', ['jane']));
        self::assertStringContainsString('"jane" and the site "jane-shop" would nest: jane-shop\'s names would start with "jane-"', (string) NestedNames::refusal('jane', ['jane-shop']));
        self::assertNull(NestedNames::refusal('kim', ['jane', 'jane-shop']));
    }

    public function testEachPairOnceShorterFirst(): void
    {
        self::assertSame([['jane', 'jane-shop'], ['jane', 'jane-shop-two'], ['jane-shop', 'jane-shop-two']], NestedNames::pairs(['jane-shop-two', 'kim', 'jane', 'jane-shop', 'jane']));
        self::assertSame([], NestedNames::pairs(['jane', 'janet', 'kim']));
    }
}
