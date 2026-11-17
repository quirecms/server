<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Site\SiteName;

/**
 * What a running site does with its packages: which of them are extensions it runs, and its theme.
 */
interface SiteExtensions
{
    /**
     * @return array{extensions: list<array{id: string, package: string, status: string}>, theme: ?string}
     *
     * @throws \RuntimeException When the site can't tell (it doesn't run, its report can't be read)
     */
    public function of(SiteName $name): array;
}
