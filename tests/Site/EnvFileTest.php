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
        foreach (["x'y", "x\ny", "x\0y"] as $value) {
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
}
