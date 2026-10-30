<?php

declare(strict_types=1);

namespace Quire\Server\Process;

/**
 * A file the server wrote, on the disk: not only in the kernel's cache, where a power cut loses it
 * (ext4 commits every few seconds). What the server writes down to rely on later goes through here:
 * its settings, a site's settings, its backups.
 */
final class Durable
{
    public static function sync(string $path): void
    {
        $handle = @fopen($path, 'r');

        if (false === $handle) {
            throw new \RuntimeException(\sprintf('"%s" can\'t be read to put it on the disk.', $path));
        }

        try {
            if (!fsync($handle)) {
                throw new \RuntimeException(\sprintf('"%s" couldn\'t be put on the disk.', $path));
            }
        } finally {
            fclose($handle);
        }
    }
}
