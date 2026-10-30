<?php

declare(strict_types=1);

namespace Quire\Server\Site;

/**
 * An env file as Docker Compose reads it (KEY=value, # comments, '…' taken as is, "…" with escapes,
 * an optional "export "): read, and changed one variable at a time, every other line kept as it was
 * (a person may have written it).
 */
final readonly class EnvFile
{
    private const string LINE = '/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\z/';

    /**
     * @param list<string> $lines
     */
    private function __construct(
        private array $lines,
    ) {
    }

    public static function read(string $path): self
    {
        $content = @file_get_contents($path);

        if (false === $content) {
            throw new \RuntimeException(\sprintf('Couldn\'t read "%s".', $path));
        }

        return self::parse($content);
    }

    public static function parse(string $content): self
    {
        return new self(explode("\n", rtrim(str_replace("\r\n", "\n", $content), "\n")));
    }

    /**
     * @return array<string, string> Every variable, the last one winning (as Compose reads them)
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->lines as $line) {
            if (1 === preg_match(self::LINE, $line, $match)) {
                $values[$match[1]] = $this->value($match[2]);
            }
        }

        return $values;
    }

    public function get(string $name): ?string
    {
        return $this->values()[$name] ?? null;
    }

    /**
     * The file with these variables set: each one's line replaced where it was, or added at the end.
     *
     * @param array<string, string> $values
     */
    public function with(array $values): self
    {
        $lines = $this->lines;

        foreach ($values as $name => $value) {
            if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $name)) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a variable name.', $name));
            }

            // Single quotes are taken as they are: so no quote and no line break in a value. And none
            // ending in "\": Compose reads "\'" as an escaped quote, and the file no further.
            if (1 === preg_match('/[\'\x00-\x1f\x7f]/', $value) || str_ends_with($value, '\\')) {
                throw new \InvalidArgumentException(\sprintf('The value of %s can\'t contain quotes or control characters, or end with "\\".', $name));
            }

            $line = \sprintf("%s='%s'", $name, $value);
            $found = false;

            foreach ($lines as $index => $existing) {
                if (1 === preg_match(self::LINE, $existing, $match) && $match[1] === $name) {
                    // Every line for it: the last one would win otherwise.
                    $lines[$index] = $found ? '# '.$existing : $line;
                    $found = true;
                }
            }

            if (!$found) {
                $lines[] = $line;
            }
        }

        return new self($lines);
    }

    public function content(): string
    {
        return implode("\n", $this->lines)."\n";
    }

    private function value(string $raw): string
    {
        $raw = trim($raw);

        if (str_starts_with($raw, "'")) {
            $end = strpos($raw, "'", 1);

            return false === $end ? substr($raw, 1) : substr($raw, 1, $end - 1);
        }

        if (str_starts_with($raw, '"')) {
            $value = '';

            for ($i = 1, $length = \strlen($raw); $i < $length; ++$i) {
                if ('"' === $raw[$i]) {
                    break;
                }

                if ('\\' === $raw[$i] && $i + 1 < $length) {
                    $value .= match ($raw[++$i]) {
                        'n' => "\n", 't' => "\t", default => $raw[$i],
                    };

                    continue;
                }

                $value .= $raw[$i];
            }

            return $value;
        }

        // Unquoted: a comment starts at " #".
        $comment = strpos($raw, ' #');

        return rtrim(false === $comment ? $raw : substr($raw, 0, $comment));
    }
}
