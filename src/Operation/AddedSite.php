<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

use Quire\Server\Site\Site;

final readonly class AddedSite
{
    /**
     * @param string|null $adminPassword The first admin's password, when one was made (not invited): shown once
     */
    public function __construct(
        public Site $site,
        #[\SensitiveParameter]
        public ?string $adminPassword,
    ) {
    }
}
