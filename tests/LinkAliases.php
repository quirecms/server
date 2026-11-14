<?php

declare(strict_types=1);

namespace Quire\Server\Tests;

/**
 * A site's names on its link, as a file the server wrote has them: its scope (JSON) or its override (YAML, read).
 */
final class LinkAliases
{
    /**
     * @return list<string>
     */
    public static function in(mixed $document, string $link, string ...$path): array
    {
        foreach ([...$path, 'networks', $link, 'aliases'] as $key) {
            $document = \is_array($document) ? $document[$key] ?? null : null;
        }

        return \is_array($document) ? array_values(array_filter($document, \is_string(...))) : [];
    }
}
