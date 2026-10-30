<?php

declare(strict_types=1);

namespace Quire\Server\ServerDatabase;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The PostgreSQL major versions a release of the server runs, each with its image pinned by digest:
 * its default in compose.yaml (what new servers get), and every other one in a file of its own,
 * database/postgres-<major>.yaml, that server.env's COMPOSE_FILE names on servers running that one.
 * Which major a server runs is its database's own (its data), never only a setting: moving between
 * majors is an upgrade (DatabaseUpgrader).
 */
final readonly class PostgresImages
{
    public const string FOLDER = 'database';

    private const string IMAGE = '/^postgres:(\d+)(?!\d)[^@\s]*@sha256:[0-9a-f]{64}\z/';
    private const string FILE = '/^postgres-(\d+)\.yaml\z/';

    /**
     * @param int                $default The major in compose.yaml
     * @param array<int, string> $images  Every major it runs, and its image
     */
    private function __construct(
        public int $default,
        private array $images,
    ) {
    }

    /**
     * A release's, from its folder.
     *
     * @throws \RuntimeException When its files don't name PostgreSQL images as they should
     */
    public static function of(string $folder): self
    {
        $default = self::read($folder.'/compose.yaml');
        $images = [$default[0] => $default[1]];
        $files = is_dir($folder.'/'.self::FOLDER) ? scandir($folder.'/'.self::FOLDER) : [];

        foreach (false === $files ? [] : $files as $file) {
            if (1 !== preg_match(self::FILE, $file, $match)) {
                continue;
            }

            [$major, $image] = self::read($folder.'/'.self::FOLDER.'/'.$file);

            if ($major !== (int) $match[1] || $major === $default[0]) {
                throw new \RuntimeException(\sprintf('%s/%s names PostgreSQL %d.', self::FOLDER, $file, $major));
            }

            $images[$major] = $image;
        }

        ksort($images);

        return new self($default[0], $images);
    }

    /**
     * @return list<int>
     */
    public function majors(): array
    {
        return array_keys($this->images);
    }

    public function supports(int $major): bool
    {
        return isset($this->images[$major]);
    }

    /**
     * @throws \DomainException When the release doesn't run it
     */
    public function image(int $major): string
    {
        return $this->images[$major] ?? throw new \DomainException(\sprintf('This release of Quire Server doesn\'t run PostgreSQL %d.', $major));
    }

    /**
     * COMPOSE_FILE for a server running that major: its other files as they are, and the major's own
     * file when it isn't the default.
     *
     * @throws \DomainException When the release doesn't run it
     */
    public function composeFile(string $current, int $major): string
    {
        $this->image($major);
        $files = array_values(array_filter(explode(':', $current), static fn (string $file): bool => '' !== $file && 1 !== preg_match('#^'.self::FOLDER.'/postgres-\d+\.yaml\z#', $file)));

        if ($major !== $this->default) {
            $files[] = \sprintf('%s/postgres-%d.yaml', self::FOLDER, $major);
        }

        return implode(':', [] === $files ? ['compose.yaml'] : $files);
    }

    /**
     * @return array{int, string} The major of the database service's image in a Compose file, and the image
     */
    private static function read(string $file): array
    {
        try {
            $compose = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new \RuntimeException(\sprintf('%s can\'t be read: %s', basename($file), $e->getMessage()), $e->getCode(), previous: $e);
        }

        $image = \is_array($compose) && \is_array($compose['services'] ?? null) && \is_array($compose['services']['database'] ?? null) ? $compose['services']['database']['image'] ?? null : null;

        // Pinned by digest, always: a tag can be moved to another image.
        if (!\is_string($image) || 1 !== preg_match(self::IMAGE, $image, $match)) {
            throw new \RuntimeException(\sprintf('%s doesn\'t name a PostgreSQL image pinned by digest for the database.', basename($file)));
        }

        return [(int) $match[1], $image];
    }
}
