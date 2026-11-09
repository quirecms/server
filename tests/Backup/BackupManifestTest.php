<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Backup\BackupManifest;

/**
 * A quire:backup file's manifest (server contract 2), written by the site's code: read strictly, and
 * what it names only ever shown, made safe to show.
 */
final class BackupManifestTest extends TestCase
{
    public function testACompleteBackup(): void
    {
        $manifest = BackupManifest::parse('{"format": 1, "complete": true, "absent": [], "tables": {"quire.content": 3}}');

        self::assertSame([true, [], 0], [$manifest->complete, $manifest->absent, $manifest->more]);
    }

    public function testABackupOfServerContract1SaysNothing(): void
    {
        self::assertNull(BackupManifest::parse('{"format": 1, "tables": {}}')->complete, 'Its exit code decides, as before.');
    }

    public function testAnIncompleteBackupNamesEachFileWithWhy(): void
    {
        $manifest = BackupManifest::parse((string) json_encode(['complete' => false, 'absent' => [
            ['file' => 'acme-gone:00/00/a.jpg', 'reason' => 'unknown_disk'],
            ['file' => 'media:ab/cd.jpg', 'reason' => 'missing'],
            ['file' => 'media:ef/01.pdf', 'reason' => 'damaged'],
        ]]));

        self::assertFalse($manifest->complete);
        self::assertSame("acme-gone:00/00/a.jpg (unknown_disk)\nmedia:ab/cd.jpg (missing)\nmedia:ef/01.pdf (damaged)", $manifest->describe());
    }

    public function testManyAbsentFilesAreCounted(): void
    {
        $absent = array_map(static fn (int $i): array => ['file' => 'media:'.$i, 'reason' => 'missing'], range(1, 30));
        $manifest = BackupManifest::parse((string) json_encode(['complete' => false, 'absent' => $absent]));

        self::assertCount(BackupManifest::LISTED, $manifest->absent);
        self::assertStringEndsWith("media:25 (missing)\n… and 5 more", $manifest->describe());
    }

    public function testWhatItNamesIsShownSafely(): void
    {
        // A terminal would act on escape sequences; a name may be as long as the site likes.
        $manifest = BackupManifest::parse((string) json_encode(['complete' => false, 'absent' => [
            ['file' => "media:\e]0;owned\x07\e[2Jx.jpg\nfake line", 'reason' => "missing\r"],
            ['file' => 'media:'.str_repeat('é', 400), 'reason' => 'missing'],
        ]]));

        self::assertSame('media:?]0;owned??[2Jx.jpg?fake line (missing?)', $manifest->absent[0]);
        foreach ($manifest->absent as $line) {
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $line, 'One line each: none of its own.');
        }
        self::assertLessThanOrEqual(512 + \strlen('… (missing)'), \strlen($manifest->absent[1]));
        self::assertTrue(mb_check_encoding($manifest->absent[1], 'UTF-8'), 'Cut between characters, not inside one.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notAManifest(): iterable
    {
        yield 'not JSON' => ['tar: manifest.json: Not found in archive', 'isn\'t JSON'];
        yield 'a list' => ['[]', 'isn\'t a JSON object'];
        yield 'complete as a string' => ['{"complete": "false", "absent": []}', '"complete" isn\'t true or false'];
        yield 'absent as an object' => ['{"complete": false, "absent": {"file": "a"}}', '"absent" isn\'t a list'];
        yield 'an entry without why' => ['{"complete": false, "absent": [{"file": "media:a"}]}', 'isn\'t {file, reason}'];
        yield 'an entry that is a path array' => ['{"complete": false, "absent": [{"file": ["../../etc"], "reason": "missing"}]}', 'isn\'t {file, reason}'];
        yield 'incomplete naming nothing' => ['{"complete": false, "absent": []}', 'names no file'];
        yield 'complete naming files' => ['{"complete": true, "absent": [{"file": "media:a", "reason": "missing"}]}', 'doesn\'t say it\'s incomplete'];
        yield 'too deep' => [str_repeat('{"a":', 100).'1'.str_repeat('}', 100), 'isn\'t JSON'];
        yield 'too large' => ['{"complete": true, "absent": [], "x": "'.str_repeat('x', BackupManifest::MAX_SIZE).'"}', 'larger than any manifest is'];
    }

    #[DataProvider('notAManifest')]
    public function testWhatIsntAManifestIsRefused(string $json, string $why): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains($why);
        BackupManifest::parse($json);
    }
}
