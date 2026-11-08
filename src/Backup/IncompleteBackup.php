<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

/**
 * A backup that doesn't have every stored file, where one that does is needed.
 */
final class IncompleteBackup extends \RuntimeException
{
    public function __construct(public readonly SiteBackup $backup, string $why, bool $kept = true)
    {
        parent::__construct(\sprintf(
            'The backup is incomplete: some stored files aren\'t in it, and %s.%s %s',
            $why,
            $kept ? ' It\'s kept at '.$backup->file.'.' : '',
            trim((string) $backup->absent),
        ));
    }
}
