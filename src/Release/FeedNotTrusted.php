<?php

declare(strict_types=1);

namespace Quire\Server\Release;

/**
 * Quire's release feed was got, and doesn't check out (its signature, or its content): not an outage,
 * a feed someone may have tampered with. Nothing that would rely on it goes ahead.
 */
final class FeedNotTrusted extends \RuntimeException
{
}
