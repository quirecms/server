<?php

declare(strict_types=1);

namespace Quire\Server\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Rules for the server's code that a review could miss: it's the code that can act on Docker.
 */
final class ConventionsTest extends TestCase
{
    public function testPatternsEndWithZNotDollar(): void
    {
        // "$" also matches before a trailing newline: "jane\n" would pass /^[a-z]+$/.
        foreach ($this->sources() as $path => $code) {
            self::assertSame([], $this->found('/preg_match\(\s*\'[^\']*\$\/[a-zA-Z]*\'/', $code), $path.': anchor patterns with \z, not $.');
        }
    }

    public function testProgramsRunOnlyThroughTheProcessRunner(): void
    {
        // A list of arguments, never a shell: nothing in a name or a domain is ever interpreted.
        foreach ($this->sources() as $path => $code) {
            self::assertSame([], $this->found('/(?<![\w>:$])(?<!function )(?:exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(|`/', $code), $path.' runs a program outside the process runner.');
        }
    }

    /**
     * @return array<string, string> Path => code
     */
    private function sources(): array
    {
        $sources = [];

        foreach (new Finder()->in(__DIR__.'/../src')->name('*.php')->files() as $file) {
            $sources[$file->getRelativePathname()] = $file->getContents();
        }

        self::assertNotSame([], $sources);

        return $sources;
    }

    /**
     * @return list<string> What a pattern finds in the code
     */
    private function found(string $pattern, string $code): array
    {
        preg_match_all($pattern, $code, $matches);

        return $matches[0];
    }
}
