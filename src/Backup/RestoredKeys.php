<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Symfony\Component\Filesystem\Filesystem;

/**
 * A Git mode site's deploy key, from a server's backup to where this server keeps it: plain files
 * only. A backup is someone else's files; a link in it could point at any of this server's (another
 * site's key, which "site:key" would then print).
 */
final class RestoredKeys
{
    /**
     * @return bool Whether it was put in place (not when the backup has none, or this server has one)
     *
     * @throws \DomainException When it holds anything but plain files
     */
    public static function install(string $from, string $to): bool
    {
        if ('' === $from || is_link($from) || !is_dir($from) || file_exists($to)) {
            return false;
        }

        $entries = scandir($from);
        $files = [];

        foreach (false === $entries ? [] : array_diff($entries, ['.', '..']) as $entry) {
            if (is_link($from.'/'.$entry) || !is_file($from.'/'.$entry)) {
                throw new \DomainException(\sprintf('The deploy key in the backup holds "%s", which isn\'t a plain file: not restored.', $entry));
            }

            $files[] = $entry;
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir($to, 0o700);

        foreach ($files as $file) {
            $filesystem->copy($from.'/'.$file, $to.'/'.$file);
            $filesystem->chmod($to.'/'.$file, 0o600);
        }

        return true;
    }
}
