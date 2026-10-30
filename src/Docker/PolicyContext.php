<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

/**
 * What one site is allowed in its Compose configuration: its project name, its folder, and which of
 * its services may join which shared network with which aliases.
 */
final readonly class PolicyContext
{
    /** Where sites' images are named: "quire-sites/<project>/<name>" */
    public const string IMAGES = 'quire-sites/';

    public string $directory;

    /**
     * @param array<string, array<string, list<string>>> $sharedNetworks Shared network => service => the aliases it may have there, e.g.
     *                                                                   ['quire-edge' => ['php' => ['jane.example']], 'quire-data' => ['php' => [], 'worker' => []]]
     */
    public function __construct(
        public string $project,
        string $directory,
        public array $sharedNetworks,
    ) {
        $real = realpath($directory);

        if (false === $real || !is_dir($real)) {
            throw new \InvalidArgumentException(\sprintf('The site\'s folder "%s" doesn\'t exist.', $directory));
        }

        $this->directory = $real;
    }

    /**
     * Whether a path is the site's folder or inside it, symbolic links followed: a link in the folder
     * to a file elsewhere (e.g. another site's prod.env) is outside.
     */
    public function contains(string $path): bool
    {
        if ('' === $path || !str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }

        $resolved = $this->resolve($path);

        return null !== $resolved && ($resolved === $this->directory || str_starts_with($resolved, $this->directory.'/'));
    }

    /**
     * Whether a Docker object name (volume, network) is the project's own: "<project>_…".
     */
    public function ownsName(string $name): bool
    {
        return str_starts_with($name, $this->project.'_') && \strlen($name) > \strlen($this->project) + 1;
    }

    public function isSharedNetwork(string $network): bool
    {
        return isset($this->sharedNetworks[$network]);
    }

    /**
     * @return list<string>|null The aliases a service may have on a shared network, or null when it may not join it
     */
    public function aliases(string $network, string $service): ?array
    {
        return $this->sharedNetworks[$network][$service] ?? null;
    }

    /**
     * The real path: of the path itself when it exists, otherwise of its deepest existing folder with
     * the rest appended, so a symbolic link anywhere along it is followed. Null when ".." would leave
     * the root.
     */
    private function resolve(string $path): ?string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                if ([] === $parts) {
                    return null;
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        $missing = [];

        while (true) {
            $real = realpath('/'.implode('/', $parts));

            if (false !== $real) {
                return rtrim($real, '/').([] === $missing ? '' : '/'.implode('/', array_reverse($missing)));
            }

            if ([] === $parts) {
                return null;
            }

            $missing[] = array_pop($parts);
        }
    }
}
