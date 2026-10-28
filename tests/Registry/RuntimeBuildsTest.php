<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Registry;

use Quire\Server\Registry\RuntimeBuilds;

final class RuntimeBuildsTest extends RegistryTestCase
{
    public function testWhatASiteWasLastBuiltOn(): void
    {
        $builds = new RuntimeBuilds($this->connection, $this->schema);
        self::assertNull($builds->of('jane'), 'Not known');

        $builds->record('jane', ['RUNTIME' => 'ghcr.io/quirecms/runtime:8.5@sha256:aa', 'RUNTIME_BUILDER' => 'ghcr.io/quirecms/runtime:8.5-builder@sha256:bb']);
        self::assertSame(['RUNTIME' => 'ghcr.io/quirecms/runtime:8.5@sha256:aa', 'RUNTIME_BUILDER' => 'ghcr.io/quirecms/runtime:8.5-builder@sha256:bb'], $builds->of('jane'));

        $builds->record('jane', []);
        self::assertSame([], $builds->of('jane'), 'Built since on its own base images');
    }
}
