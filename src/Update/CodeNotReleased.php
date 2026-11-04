<?php

declare(strict_types=1);

namespace Quire\Server\Update;

/**
 * A site's code isn't the code Quire released, as its signed feed says (D-38): nothing is built.
 */
final class CodeNotReleased extends \RuntimeException
{
}
