<?php

declare(strict_types=1);

namespace Quire\Server\Host;

/**
 * The machine's memory, as its kernel says (/proc/meminfo: a container reads the host's): what's
 * available, and whether a site may be built now. A build is a site's biggest moment (Composer, then
 * its image exported), and the one that ran a full machine out of memory: the kernel then killed
 * whatever it picked, PostgreSQL's processes included (droplet B, 2026-11-05).
 */
final readonly class Memory
{
    /** What building a site may take, by default (QUIRE_SERVER_BUILD_MEMORY_MB): its peak, with room */
    public const int BUILD_MEGABYTES = 1536;

    /** What a site takes once it runs, about (droplet B: 208 MB a site at 133 sites) */
    public const int SITE_MEGABYTES = 200;

    public function __construct(
        private int $buildMegabytes = self::BUILD_MEGABYTES,
        private string $meminfo = '/proc/meminfo',
    ) {
    }

    /** Bytes the kernel can give without swapping (MemAvailable), or null when it doesn't say */
    public function available(): ?int
    {
        return $this->field('MemAvailable');
    }

    /** The machine's memory in bytes (MemTotal), or null when the kernel doesn't say */
    public function total(): ?int
    {
        return $this->field('MemTotal');
    }

    /**
     * Refuses to start a new site's build on a machine without room for it: said before anything is
     * made, so there's nothing to undo. A machine whose memory can't be read isn't refused.
     *
     * @throws \DomainException
     */
    public function ensureRoomToBuild(): void
    {
        $available = $this->available();

        if (null === $available || $available >= $this->buildMegabytes * 1024 ** 2) {
            return;
        }

        throw new \DomainException(\sprintf('This server has %s of memory free: building a new site takes about %s, and running it about %d MB. Add memory, or remove a site, then try again. (QUIRE_SERVER_BUILD_MEMORY_MB in server.env sets what a build needs, %d MB here.)', self::size($available), self::size($this->buildMegabytes * 1024 ** 2), self::SITE_MEGABYTES, $this->buildMegabytes));
    }

    public static function size(int|float $bytes): string
    {
        return $bytes >= 1024 ** 3 ? \sprintf('%.1f GB', $bytes / 1024 ** 3) : \sprintf('%d MB', (int) ($bytes / 1024 ** 2));
    }

    private function field(string $name): ?int
    {
        $content = @file_get_contents($this->meminfo);

        if (false === $content || 1 !== preg_match('/^'.$name.':\s+(\d+) kB$/m', $content, $match)) {
            return null;
        }

        return (int) $match[1] * 1024;
    }
}
