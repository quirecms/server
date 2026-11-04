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
        // A byte-order mark is no part of the first variable's name (Compose skips it too)
        $content = str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;

        return new self(explode("\n", rtrim(str_replace("\r\n", "\n", $content), "\n")));
    }

    /**
     * A variable the server acts on, and Compose reads too (COMPOSE_FILE, COMPOSE_PROJECT_NAME,
     * SERVER_NAME): only when its line is written so that both read the same value (FUZZ-11). Compose
     * expands "$NAME" and "${NAME:-…}", reads "NAME: value" and quotes over several lines; this reader
     * doesn't. Null when it isn't set.
     *
     * @throws \DomainException When its line isn't one both read alike, with how to write it
     */
    public function clear(string $name): ?string
    {
        $found = null;

        foreach ($this->lines as $number => $line) {
            if (1 === preg_match('/^\s*(?:export\s+)?'.preg_quote($name, '/').'\s*[:=]/', $line)) {
                $found = [$number + 1, $line];
            }
        }

        if (null === $found) {
            return null;
        }

        [$number, $line] = $found;
        $raw = 1 === preg_match(self::LINE, $line, $match) ? trim($match[2]) : null;
        $clear = null !== $raw && match (true) {
            str_starts_with($raw, "'") => 1 === preg_match("/^'[^']*'(?:\\s+#.*)?\\z/", $raw),
            str_starts_with($raw, '"') => 1 === preg_match('/^"[^"$\\\\]*"(?:\s+#.*)?\z/', $raw),
            default => 1 === preg_match('/^[^\'"$\\\\]*\z/', $raw),
        };

        if (!$clear) {
            throw new \DomainException(\sprintf('Line %d of the env file (%s) isn\'t written so that Quire Server reads it as Docker Compose does: write it as %s=\'value\' (no "$", no line break in it).', $number, mb_substr($line, 0, 80), $name));
        }

        return $this->value($raw);
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
     * @param array<array-key, string> $values
     */
    public function with(array $values): self
    {
        $lines = $this->lines;

        foreach ($values as $name => $value) {
            // (A name of digits only comes as an integer key: refused as the others are)
            if (!\is_string($name) || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $name)) {
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
