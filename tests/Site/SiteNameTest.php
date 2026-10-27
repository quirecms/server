<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Site;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Site\SiteName;

final class SiteNameTest extends TestCase
{
    public function testAValidNameNamesItsDatabase(): void
    {
        $name = new SiteName('jane-shop2');

        self::assertSame('jane-shop2', (string) $name);
        self::assertSame('site_jane_shop2', $name->databaseIdentifier());
        self::assertTrue($name->equals(new SiteName('jane-shop2')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'one character' => ['j'];
        yield 'too long' => [str_repeat('a', 31)];
        yield 'uppercase' => ['Jane'];
        yield 'starts with a digit' => ['2jane'];
        yield 'starts with a dash' => ['-jane'];
        yield 'ends with a dash' => ['jane-'];
        yield 'double dash' => ['ja--ne'];
        yield 'underscore' => ['ja_ne'];
        yield 'dot' => ['jane.example'];
        yield 'path traversal' => ['../etc'];
        yield 'slash' => ['ja/ne'];
        yield 'space' => ['ja ne'];
        yield 'newline' => ["jane\n"];
        yield 'NUL byte' => ["jane\0x"];
        yield 'SQL' => ['jane;drop'];
        yield 'quote' => ['ja"ne'];
        yield 'unicode lookalike' => ['jаne'];
        yield 'shell' => ['$(id)'];
    }

    #[DataProvider('invalid')]
    public function testOnlyTheAllowListPasses(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteName($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reserved(): iterable
    {
        foreach (['server', 'agent', 'front', 'database', 'docker-proxy', 'console', 'sites', 'backups', 'quire', 'quire-server', 'quire-edge', 'quiredata'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('reserved')]
    public function testTheServersOwnNamesAreReserved(string $value): void
    {
        $this->expectExceptionMessageIsOrContains('is reserved for the server itself');
        new SiteName($value);
    }

    public function testMessagesShowWhatWasGivenSafely(): void
    {
        try {
            new SiteName("evil\e[31m".str_repeat('x', 100));
            self::fail('Not refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('evil\x1b[31m', $e->getMessage(), 'Control characters escaped, not sent to the terminal.');
            self::assertStringContainsString('…', $e->getMessage(), 'Shortened.');
        }
    }
}
