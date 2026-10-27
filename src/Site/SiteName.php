<?php

declare(strict_types=1);

namespace Quire\Server\Site;

/**
 * A site's short name on the server, e.g. "jane": its folder (/srv/quire/sites/jane), its Docker
 * Compose project, and its database and role ("site_jane"). It's used in paths, container names and
 * SQL identifiers, so it's an allow-list: lowercase letters and digits, single dashes between them,
 * 2 to 30 characters, starting with a letter. Names the server uses itself are reserved.
 */
final readonly class SiteName implements \Stringable
{
    private const string PATTERN = '/^[a-z](?:[a-z0-9]|-(?=[a-z0-9])){1,29}\z/';

    /** Names of the server's own parts, and names easy to confuse with them */
    private const array RESERVED = ['agent', 'backups', 'console', 'database', 'default', 'docker', 'docker-proxy', 'front', 'localhost', 'none', 'root', 'server', 'sites'];

    public string $value;

    public function __construct(string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a site name: use 2 to 30 lowercase letters, digits and single dashes, starting with a letter, e.g. "jane".', self::printable($value)));
        }

        if (\in_array($value, self::RESERVED, true) || str_starts_with($value, 'quire')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is reserved for the server itself; choose another site name.', $value));
        }

        $this->value = $value;
    }

    /**
     * The name of the site's database and of its PostgreSQL role, e.g. "site_jane".
     */
    public function databaseIdentifier(): string
    {
        return 'site_'.str_replace('-', '_', $this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * What was given, safe to show in a message: control characters escaped, at most 60 characters.
     */
    public static function printable(string $value): string
    {
        $escaped = (string) preg_replace_callback('/[^\x20-\x7e]/', static fn (array $match): string => \sprintf('\x%02x', \ord($match[0])), $value);

        return mb_strlen($escaped) > 60 ? mb_substr($escaped, 0, 60).'…' : $escaped;
    }
}
