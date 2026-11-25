<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Quire\Server\Site\SiteName;

/**
 * An operation on a site was refused because its lock is held: the server's (it's being updated) or
 * the site's own (another operation on it runs). Nothing was done, and the same operation works once
 * the holder is finished, so a caller that may wait tries it again later (SiteOverrides at the agent's
 * start, which an update of the server runs while it holds the server's lock).
 */
final class LockBusy extends \DomainException
{
    public static function server(): self
    {
        return new self('The server is being updated right now; try again when it\'s finished ("quire-server operations").');
    }

    public static function site(SiteName $site): self
    {
        return new self(\sprintf('Something else is being done to the site "%s" right now; try again when it\'s finished.', $site));
    }
}
