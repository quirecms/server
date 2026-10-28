<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The runtime images each site's image was last built on (build argument => image by digest), by its
 * Compose project: written after every build, read to tell whether a newer runtime came since.
 */
final readonly class RuntimeBuilds
{
    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    /**
     * @param array<string, string> $images
     */
    public function record(string $project, array $images): void
    {
        ksort($images);
        $this->connection->executeStatement(
            \sprintf('INSERT INTO %s.site_runtime (project, images) VALUES (?, ?) ON CONFLICT (project) DO UPDATE SET images = EXCLUDED.images, built_at = now()', $this->connection->quoteSingleIdentifier($this->schema)),
            [$project, $images],
            [Types::STRING, Types::JSON],
        );
    }

    /**
     * @return array<string, string>|null What it was last built on, or null when it isn't known (built before this was recorded)
     */
    public function of(string $project): ?array
    {
        $images = $this->connection->fetchOne(\sprintf('SELECT images FROM %s.site_runtime WHERE project = ?', $this->connection->quoteSingleIdentifier($this->schema)), [$project]);

        if (!\is_string($images)) {
            return null;
        }

        $decoded = json_decode($images, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $built = [];

        foreach ($decoded as $name => $image) {
            if (\is_string($name) && \is_string($image)) {
                $built[$name] = $image;
            }
        }

        return $built;
    }
}
