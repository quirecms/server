<?php

declare(strict_types=1);

namespace Quire\Server\Site;

/**
 * Where things are on the server: /srv/quire by default (QUIRE_SERVER_ROOT for tests).
 */
final readonly class ServerPaths
{
    public string $root;

    public function __construct(string $root = '/srv/quire')
    {
        if (!str_starts_with($root, '/') || str_contains($root, '..')) {
            throw new \InvalidArgumentException('The server\'s root must be an absolute path.');
        }

        $this->root = rtrim($root, '/');
    }

    public function sites(): string
    {
        return $this->root.'/sites';
    }

    public function site(SiteName $site): string
    {
        return $this->sites().'/'.$site->value;
    }

    /**
     * Where a removed site's folder goes, kept until it's purged.
     */
    public function removed(SiteName $site, \DateTimeImmutable $at): string
    {
        return $this->sites().'/.removed/'.$site->value.'-'.$at->format('Ymd-His');
    }

    public function backups(SiteName $site): string
    {
        return $this->root.'/backups/'.$site->value;
    }

    public function domains(): string
    {
        return $this->root.'/server/domains';
    }

    /**
     * A site's deploy key and the hosts it trusts (Git mode): outside its folder.
     */
    public function keys(SiteName $site): string
    {
        return $this->root.'/server/keys/'.$site->value;
    }
}
