<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

/**
 * A runtime image a site would build on whose signature didn't check out: the site isn't built.
 */
final class RuntimeImageNotTrusted extends \RuntimeException
{
}
