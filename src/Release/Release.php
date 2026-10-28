<?php

declare(strict_types=1);

namespace Quire\Server\Release;

/**
 * A release of Quire Server whose signatures checked out: its files, extracted, and its agent's image,
 * by digest.
 */
final readonly class Release
{
    public function __construct(
        public Version $version,
        public string $image,
        public string $files,
    ) {
    }
}
