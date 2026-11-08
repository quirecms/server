<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

/**
 * A site's quire:backup file, and whether it has everything the site's data lists.
 *
 * quire:backup exits 3 when it wrote the backup but some stored files aren't in it (a disk it can't
 * reach, a file gone or damaged). The file is still kept: it's the best copy there is. Each use decides
 * what an incomplete one means: an update stops, since its backup is the way back; restoring an older
 * backup goes on, since this one only keeps what is there now.
 */
final readonly class SiteBackup
{
    /** quire:backup's exit code for "written, but some stored files aren't in it" (Quire\Runtime\ServerContract) */
    public const int INCOMPLETE_EXIT = 3;

    /**
     * @param string      $file   Where it is
     * @param string|null $absent What quire:backup said is missing from it; null when it's complete
     */
    public function __construct(
        public string $file,
        public ?string $absent = null,
    ) {
    }

    public function complete(): bool
    {
        return null === $this->absent;
    }

    /**
     * The file, when the backup is complete.
     *
     * @param string $why  Why that's not enough here, after "and"
     * @param bool   $kept Whether the file stays where it is, to say where
     *
     * @throws IncompleteBackup
     */
    public function orStop(string $why, bool $kept = true): string
    {
        if (null !== $this->absent) {
            throw new IncompleteBackup($this, $why, $kept);
        }

        return $this->file;
    }
}
