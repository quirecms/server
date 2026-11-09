<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Update\ContractNotKept;
use Quire\Server\Update\ReleaseCheck;
use Quire\Server\Update\SiteContract;

/**
 * The server contract a site's code keeps, from its quire:about report: an untrusted answer, read
 * strictly.
 */
final class SiteContractTest extends TestCase
{
    public function testTheContractIsTheReports(): void
    {
        self::assertSame(2, SiteContract::of($this->about('{"contract": 2, "quire": "0.2.0", "health": {"ok": true}}')));
    }

    public function testQuireBefore011KeepsNone(): void
    {
        // quire:about came with the contract: "Command not defined", exit 1
        self::assertSame(0, SiteContract::of(new ProcessResult(['bin/console', 'quire:about'], 1, '', 'Command "quire:about" is not defined.')));
        self::assertSame(0, SiteContract::of($this->about('{"quire": "0.1.0"}')), 'A report without one.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notAReport(): iterable
    {
        yield 'not JSON' => ['Quire 0.2.0', 'isn\'t JSON'];
        yield 'a list' => ['[2]', 'isn\'t one'];
        yield 'a number' => ['2', 'isn\'t one'];
        yield 'a string contract' => ['{"contract": "2"}', 'isn\'t one (a whole number from 1)'];
        yield 'a fraction' => ['{"contract": 2.5}', 'isn\'t one (a whole number from 1)'];
        yield 'zero' => ['{"contract": 0}', 'isn\'t one (a whole number from 1)'];
        yield 'negative' => ['{"contract": -1}', 'isn\'t one (a whole number from 1)'];
        yield 'too deep' => [str_repeat('[', 100).str_repeat(']', 100), 'isn\'t JSON'];
        yield 'too large' => ['{"contract": 2, "padding": "'.str_repeat('x', SiteContract::MAX_REPORT).'"}', 'larger than any report is'];
    }

    #[DataProvider('notAReport')]
    public function testWhatIsntAReportIsRefused(string $output, string $why): void
    {
        $this->expectException(ContractNotKept::class);
        $this->expectExceptionMessageIsOrContains($why);
        SiteContract::of($this->about($output));
    }

    public function testANewerContractThanTheServersIsRefused(): void
    {
        SiteContract::ensureKept(0);
        SiteContract::ensureKept(ReleaseCheck::CONTRACT);

        $this->expectException(ContractNotKept::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('The site\'s Quire keeps server contract %d; this server knows up to %d: update the server first ("quire-server update").', ReleaseCheck::CONTRACT + 1, ReleaseCheck::CONTRACT));
        SiteContract::ensureKept(ReleaseCheck::CONTRACT + 1);
    }

    private function about(string $output): ProcessResult
    {
        return new ProcessResult(['bin/console', 'quire:about', '--format=json'], 0, $output, '');
    }
}
