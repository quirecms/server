<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

/**
 * The key this server signs its backups with is gone, or isn't the one it signs with: no backup is
 * signed with another, since other servers trust that one (D-36).
 */
final class SigningKeyLost extends \RuntimeException
{
}
