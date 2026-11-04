<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Site;

use PHPUnit\Framework\TestCase;
use Quire\Server\Site\EnvFile;

final class EnvFileTest extends TestCase
{
    private const string WRITTEN_BY_A_PERSON = <<<'ENV'
        # The site's production settings
        COMPOSE_FILE=compose.yaml:compose.prod.yaml
        SERVER_NAME=demo.quirecms.com   # the domain
        export APP_SECRET='abc#def'
        MAILER_DSN="smtp://darko%40poposki.mk:p\"w@smtp.gmail.com:587"

        POSTGRES_PASSWORD = spaced
        ENV;

    public function testItReadsWhatComposeReads(): void
    {
        self::assertSame([
            'COMPOSE_FILE' => 'compose.yaml:compose.prod.yaml',
            'SERVER_NAME' => 'demo.quirecms.com',
            'APP_SECRET' => 'abc#def',
            'MAILER_DSN' => 'smtp://darko%40poposki.mk:p"w@smtp.gmail.com:587',
            'POSTGRES_PASSWORD' => 'spaced',
        ], EnvFile::parse(self::WRITTEN_BY_A_PERSON)->values());
    }

    public function testChangingAVariableKeepsEveryOtherLine(): void
    {
        $changed = EnvFile::parse(self::WRITTEN_BY_A_PERSON)->with([
            'COMPOSE_FILE' => 'compose.yaml:compose.prod.yaml:compose.server.yaml',
            'COMPOSE_PROJECT_NAME' => 'demo',
        ]);

        self::assertSame(<<<'ENV'
            # The site's production settings
            COMPOSE_FILE='compose.yaml:compose.prod.yaml:compose.server.yaml'
            SERVER_NAME=demo.quirecms.com   # the domain
            export APP_SECRET='abc#def'
            MAILER_DSN="smtp://darko%40poposki.mk:p\"w@smtp.gmail.com:587"

            POSTGRES_PASSWORD = spaced
            COMPOSE_PROJECT_NAME='demo'

            ENV, $changed->content());
        self::assertSame('abc#def', $changed->get('APP_SECRET'), 'Secrets untouched.');
    }

    public function testADuplicateIsCommentedOutSoTheNewValueWins(): void
    {
        $changed = EnvFile::parse("A=1\nA=2\n")->with(['A' => '3']);

        self::assertSame("A='3'\n# A=2\n", $changed->content());
        self::assertSame('3', $changed->get('A'));
    }

    public function testValuesThatCouldBreakTheFileAreRefused(): void
    {
        // A value ending in "\": Compose would read its closing quote as escaped, and lose the next line.
        foreach (["x'y", "x\ny", "x\0y", 'ends with \\'] as $value) {
            try {
                EnvFile::parse('')->with(['A' => $value]);
                self::fail(\sprintf('"%s" was accepted.', addcslashes($value, "\0..\37")));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('can\'t contain quotes or control characters', $e->getMessage());
            }
        }

        $this->expectExceptionMessageIsOrContains('isn\'t a variable name');
        EnvFile::parse('')->with(['A B' => 'x']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function readAsComposeDoes(): iterable
    {
        yield 'plain' => ["COMPOSE_FILE=compose.yaml:compose.prod.yaml\n", 'compose.yaml:compose.prod.yaml'];
        yield 'single quotes' => ["COMPOSE_FILE='compose.yaml'\n", 'compose.yaml'];
        yield 'double quotes, nothing to expand' => ["COMPOSE_FILE=\"compose.yaml\"  # a comment\n", 'compose.yaml'];
        yield 'after a byte-order mark' => ["\u{FEFF}COMPOSE_FILE=compose.yaml\n", 'compose.yaml'];
        yield 'the last one wins' => ["COMPOSE_FILE=a.yaml\nCOMPOSE_FILE=b.yaml\n", 'b.yaml'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readAsComposeDoes')]
    public function testAVariableWrittenSoThatComposeReadsItTheSame(string $content, string $value): void
    {
        self::assertSame($value, EnvFile::parse($content)->clear('COMPOSE_FILE'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readOtherwiseByCompose(): iterable
    {
        // The audit's differential (FUZZ-11): Compose's own reader takes these otherwise
        yield 'a variable Compose expands' => ["COMPOSE_FILE=\${A:-compose.yaml}\n"];
        yield 'one expanded in double quotes' => ["COMPOSE_FILE=\"\$HOME/compose.yaml\"\n"];
        yield 'YAML-style' => ["COMPOSE_FILE: compose.yaml\n"];
        yield 'a quote over two lines' => ["COMPOSE_FILE='compose.yaml\nother.yaml'\n"];
        yield 'an escape' => ["COMPOSE_FILE=\"compose.yaml\\r\"\n"];
        yield 'text after the quote' => ["COMPOSE_FILE='a' b\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readOtherwiseByCompose')]
    public function testOneComposeWouldReadOtherwiseIsRefused(string $content): void
    {
        $this->expectExceptionMessageIsOrContains('isn\'t written so that Quire Server reads it as Docker Compose does');
        EnvFile::parse($content)->clear('COMPOSE_FILE');
    }

    public function testDoubleQuotedValuesAndWhatFollowsThem(): void
    {
        // STATIC-08: the escapes, and text after the closing quote
        self::assertSame("a\nb\tc\\d\"e", EnvFile::parse("X=\"a\\nb\\tc\\\\d\\\"e\" ignored\n")->get('X'));
        self::assertSame('a', EnvFile::parse("X=\"a\" b\n")->get('X'), 'Nothing after the closing quote.');
        self::assertSame('unterminated', EnvFile::parse("X=\"unterminated\n")->get('X'));
        self::assertNull(EnvFile::parse("Y=1\n")->clear('X'));
    }

    public function testANameOfDigitsIsRefusedAsAnyBadName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnvFile::parse('')->with(['123' => 'x']);
    }
}
