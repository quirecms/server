<?php

declare(strict_types=1);

namespace Quire\Server\Release;

/**
 * A release that can't be trusted (a signature, a checksum or a value that doesn't check out), or that
 * couldn't be got: nothing of it is used.
 */
final class ReleaseVerificationFailed extends \RuntimeException
{
}
