<?php

declare(strict_types=1);

namespace Quire\Server\Update;

/**
 * The files a site got from quire/skeleton that Composer doesn't update (its Dockerfile, Compose files,
 * FrankenPHP's configuration…), compared three ways: the skeleton the site came from, the skeleton of
 * the new version, and the site's own. A file the site never edited follows the new skeleton; one it
 * edited that the new skeleton changes too is a conflict, never overwritten.
 */
final class SkeletonFiles
{
    public const string REPLACE = 'replace';
    public const string CREATE = 'create';
    public const string DELETE = 'delete';
    public const string CONFLICT = 'conflict';

    /** Files at the skeleton's root that belong to how it runs, not to the site's content */
    private const array FILES = ['Dockerfile', 'compose.yaml', 'compose.prod.yaml', 'compose.proxy.yaml', 'compose.override.yaml', '.dockerignore', '.gitignore', 'prod.env.dist'];

    /** Folders all of whose files are */
    private const array FOLDERS = ['frankenphp'];

    /**
     * @param string|null $base The skeleton the site came from (null when unknown: every difference is a conflict)
     *
     * @return list<array{path: string, action: string}> By path
     */
    public static function changes(string $site, ?string $base, string $target): array
    {
        $changes = [];

        foreach (self::paths($base, $target) as $path) {
            $from = null === $base ? false : self::read($base, $path);
            $to = self::read($target, $path);
            $current = self::read($site, $path);

            if ($from === $to || $current === $to) {
                continue;
            }

            $changes[] = ['path' => $path, 'action' => match (true) {
                null === $base || $current !== $from => self::CONFLICT,
                null === $to => self::DELETE,
                null === $current => self::CREATE,
                default => self::REPLACE,
            }];
        }

        return $changes;
    }

    /**
     * Applies the changes that aren't conflicts. (Conflicts are left as the site has them.).
     *
     * @param list<array{path: string, action: string}> $changes
     */
    public static function apply(string $site, string $target, array $changes): void
    {
        foreach ($changes as $change) {
            $destination = $site.'/'.self::safe($change['path']);

            if (self::DELETE === $change['action']) {
                @unlink($destination);
            } elseif (\in_array($change['action'], [self::REPLACE, self::CREATE], true)) {
                $source = $target.'/'.$change['path'];

                if (!is_dir(\dirname($destination)) && !mkdir(\dirname($destination), 0o755, true) && !is_dir(\dirname($destination))) {
                    throw new \RuntimeException(\sprintf('Couldn\'t create "%s".', \dirname($destination)));
                }

                if (!copy($source, $destination)) {
                    throw new \RuntimeException(\sprintf('Couldn\'t write "%s".', $destination));
                }

                chmod($destination, fileperms($source) & 0o755);
            }
        }
    }

    /**
     * Copies the site's current version of these files (those it has) to a folder, to put them back.
     *
     * @param list<string> $paths
     */
    public static function save(string $site, array $paths, string $to): void
    {
        foreach ($paths as $path) {
            $source = $site.'/'.self::safe($path);

            if (is_file($source) && !is_link($source)) {
                $destination = $to.'/'.$path;

                if (!is_dir(\dirname($destination))) {
                    mkdir(\dirname($destination), 0o700, true);
                }

                copy($source, $destination);
                chmod($destination, fileperms($source) & 0o755);
            }
        }
    }

    /**
     * Puts back files saved with save(): those saved are copied back, the others (created since) removed.
     *
     * @param list<string> $paths
     */
    public static function restore(string $site, array $paths, string $from): void
    {
        foreach ($paths as $path) {
            $saved = $from.'/'.self::safe($path);
            $destination = $site.'/'.$path;

            if (is_file($saved)) {
                if (!is_dir(\dirname($destination))) {
                    mkdir(\dirname($destination), 0o755, true);
                }

                copy($saved, $destination);
                chmod($destination, fileperms($saved) & 0o755);
            } else {
                @unlink($destination);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function paths(?string $base, string $target): array
    {
        $paths = [];

        foreach (array_filter([$base, $target], static fn (?string $root): bool => null !== $root) as $root) {
            foreach (self::FILES as $file) {
                if (is_file($root.'/'.$file)) {
                    $paths[$file] = true;
                }
            }

            foreach (self::FOLDERS as $folder) {
                if (!is_dir($root.'/'.$folder)) {
                    continue;
                }

                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$folder, \FilesystemIterator::SKIP_DOTS));

                foreach ($files as $file) {
                    if ($file instanceof \SplFileInfo && $file->isFile() && !$file->isLink()) {
                        $paths[substr($file->getPathname(), \strlen($root) + 1)] = true;
                    }
                }
            }
        }

        $paths = array_map(strval(...), array_keys($paths));
        sort($paths);

        return $paths;
    }

    private static function read(string $root, string $path): ?string
    {
        $file = $root.'/'.self::safe($path);

        if (!is_file($file) || is_link($file)) {
            return null;
        }

        $content = file_get_contents($file);

        return false === $content ? null : $content;
    }

    /**
     * A path inside the site: relative, without "..".
     */
    private static function safe(string $path): string
    {
        if ('' === $path || str_starts_with($path, '/') || \in_array('..', explode('/', $path), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a path inside the site.', $path));
        }

        return $path;
    }
}
