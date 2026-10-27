<?php

declare(strict_types=1);

namespace Quire\Server;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The server's agent: a console application, without a web side (the console comes in S3, in a
 * container of its own). It holds the least code it can: it's the part that can act on Docker.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
