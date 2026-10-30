<?php

declare(strict_types=1);

namespace Quire\Server\Tests\ServerDatabase;

use PHPUnit\Framework\TestCase;
use Quire\Server\ServerDatabase\DatabaseCheck;

final class DatabaseCheckTest extends TestCase
{
    private const string OLD = <<<'TXT'
        relation quire.content r rows 5000
        relation quire.content_pkey i
        relation quire.user r rows 3
        role site_jane f,t,f,f,t,f,f,50 9b22c9d8b9b9e383c0319c0d26fd1dc5
        sequence quire.content_id_seq 5000
        TXT;

    public function testTheSameIsNoDifference(): void
    {
        // Line order and trailing blanks don't matter.
        $new = implode("\n", array_reverse(explode("\n", self::OLD)))."\n\n";

        self::assertSame([], DatabaseCheck::differences(self::OLD, $new));
    }

    public function testDifferencesAreToldByKindAndName(): void
    {
        $new = str_replace(['rows 5000', "relation quire.user r rows 3\n", '9b22c9d8b9b9e383c0319c0d26fd1dc5'], ['rows 4999', '', '00000000000000000000000000000000'], self::OLD)."\nrelation quire.extra r rows 0";

        $differences = DatabaseCheck::differences(self::OLD, $new);

        self::assertSame([
            'relation quire.content (different or added)',
            'relation quire.extra (different or added)',
            'relation quire.user (missing)',
            'role site_jane (different or added)',
        ], $differences);

        // Never a line's content: a role's line holds its password's hash.
        self::assertStringNotContainsString('9b22c9d8', implode(' ', $differences));
        self::assertStringNotContainsString('rows', implode(' ', $differences));
    }

    public function testCountsTheTablesAndRows(): void
    {
        self::assertSame(['tables' => 2, 'rows' => 5003], DatabaseCheck::counts(self::OLD));
    }
}
