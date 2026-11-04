<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Site;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Site\Domain;

final class DomainTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valid(): iterable
    {
        yield 'plain' => ['jane.example', 'jane.example'];
        yield 'subdomain' => ['www.jane.example', 'www.jane.example'];
        yield 'uppercase and spaces' => ['  Jane.Example ', 'jane.example'];
        yield 'trailing dot' => ['jane.example.', 'jane.example'];
        yield 'dash and digits' => ['my-site2.co.uk', 'my-site2.co.uk'];
        yield 'international' => ['müller.example', 'xn--mller-kva.example'];
        yield 'punycode given' => ['xn--mller-kva.example', 'xn--mller-kva.example'];
        yield 'test TLD' => ['jane.test', 'jane.test'];
        yield 'one script, not Latin' => ['пример.рф', 'xn--e1afmkfd.xn--p1ai'];
        yield 'an international top-level name' => ['jane.xn--p1ai', 'jane.xn--p1ai'];
    }

    #[DataProvider('valid')]
    public function testDomainsAreKeptInTheirAsciiForm(string $given, string $stored): void
    {
        self::assertSame($stored, (string) new Domain($given));
    }

    public function testTheUnicodeFormIsForPeople(): void
    {
        self::assertSame('müller.example', new Domain('xn--mller-kva.example')->unicode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        // The audit's (FUZZ-09, ISO-11)
        yield 'punycode that isn\'t' => ['xn--zz.example'];
        yield 'a reserved "--" label' => ['ab--cd.example'];
        yield 'an address to some parsers' => ['0x7f.0x0.0x0.0x1'];
        yield 'a Docker network\'s name as top label' => ['bob-php-1.quire-edge'];
        yield 'localhost' => ['evil.localhost'];
        yield '.local (mDNS)' => ['printer.local'];
        yield '.internal (Docker\'s, cloud networks\')' => ['host.docker.internal'];
        yield '.onion' => ['jane.onion'];
        yield 'mixed scripts (a Cyrillic а)' => ['pаypal.com'];
        yield 'a whole-script lookalike' => ['аррlе.com'];
        yield 'mixed Greek' => ['pαypal.com'];
        yield 'single label' => ['localhost'];
        yield 'container name' => ['quire-database'];
        yield 'IPv4' => ['127.0.0.1'];
        yield 'IPv4 as two labels' => ['10.1'];
        yield 'IPv6' => ['[::1]'];
        yield 'wildcard' => ['*.jane.example'];
        yield 'empty label' => ['jane..example'];
        yield 'leading dot' => ['.jane.example'];
        yield 'label starts with dash' => ['-jane.example'];
        yield 'label ends with dash' => ['jane-.example'];
        yield 'underscore' => ['ja_ne.example'];
        yield 'label too long' => [str_repeat('a', 64).'.example'];
        yield 'too long' => [implode('.', array_fill(0, 5, str_repeat('a', 60))).'.example'];
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'slash' => ['jane.example/admin'];
        yield 'port' => ['jane.example:8080'];
        yield 'userinfo' => ['user@jane.example'];
        yield 'space inside' => ['jane example.com'];
        yield 'newline (header injection)' => ["jane.example\nHost: evil"];
        yield 'NUL' => ["jane.example\0.evil"];
        yield 'Caddy placeholder' => ['{http.request.host}.example'];
        yield 'invalid IDN (bidi)' => ['a‮b.example'];
        yield 'zero-width joiner' => ["ja\u{200D}ne.example"];
        yield 'right-to-left and left-to-right in one label (bidi spoofing)' => ["\u{05E9}a.example"];
    }

    #[DataProvider('invalid')]
    public function testOnlyRealPublicHostNamesPass(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Domain($value);
    }
}
