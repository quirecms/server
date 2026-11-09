<?php

declare(strict_types=1);

namespace Quire\Server\Update;

/**
 * A site's Quire keeps a newer server contract than this server knows, or says nothing that is one:
 * the server doesn't run it (its backups, for one, would be read wrongly).
 */
final class ContractNotKept extends \RuntimeException
{
}
