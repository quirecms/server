<?php

declare(strict_types=1);

namespace Quire\Server\ServerUpdate;

use Quire\Server\Release\Version;

/**
 * The oldest release this one updates from. An update is run by the release before it (its updater),
 * which can't be taught what a later release needs; so this release's first step, before any of its
 * containers starts (compose.yaml: update-from-0.6-or-later), reads the update's record (swap.json,
 * written by every updater since 0.3.1) and stops the start when the update comes from an older one.
 * That updater then puts the server back as it ran, as for any start that fails.
 *
 * 0.6.0: from 0.7 on, the server writes what YorvoGate 0.2.2 (0.5's) and 0.5's front door can't read
 * (scope files with "version", a site's only name on its link "edge.<domain>"), and an update's undo
 * leaves them as they are.
 */
final class UpdateFloor
{
    public const string OLDEST = '0.6.0';

    /**
     * Why this start must stop, or null when it may go on: no update under way (a start, a fresh
     * install, an undo's own record from this release), one from OLDEST or later, or from a server
     * built from a checkout ("dev").
     */
    public static function refusal(?string $record): ?string
    {
        if (null === $record || '' === trim($record)) {
            return null;
        }

        try {
            $swap = json_decode($record, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Not this check's to judge: the updater's own reading of it says what's wrong
            return null;
        }

        $from = \is_array($swap) && \is_string($swap['from'] ?? null) ? ltrim($swap['from'], 'v') : null;
        $version = null === $from ? null : Version::tryFrom($from);

        if (!$version instanceof Version || $version->compare(new Version(self::OLDEST)) >= 0) {
            return null;
        }

        return \sprintf('Quire Server %s updates a server on %s or later; this one runs %s. Update it to 0.6.1 first ("quire-server update --to=0.6.1"), then to this release. The server runs as it was.', self::release(), self::OLDEST, $version);
    }

    private static function release(): string
    {
        $version = getenv('QUIRE_SERVER_VERSION');

        return \is_string($version) && '' !== $version ? $version : 'this release';
    }
}
