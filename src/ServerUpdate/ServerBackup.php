<?php

declare(strict_types=1);

namespace Quire\Server\ServerUpdate;

/**
 * The server as it was before an update (/srv/quire/backups/server/<time>): its files and settings,
 * its database, and what it ran. An update is undone, or rolled back, by putting it back.
 */
final readonly class ServerBackup
{
    /** How long an update can be undone */
    public const string UNDOABLE_FOR = 'P7D';

    /**
     * @param string       $from       The release it ran ("dev": built from a checkout)
     * @param string       $to         The release it was updated to
     * @param list<string> $migrations The registry's migrations that had run
     */
    public function __construct(
        public string $directory,
        public string $from,
        public string $to,
        public array $migrations,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $undoneAt = null,
    ) {
    }

    public function files(): string
    {
        return $this->directory.'/files.tar';
    }

    public function database(): string
    {
        return $this->directory.'/database.dump';
    }

    public function save(): void
    {
        ServerFolder::writeSecret($this->directory.'/backup.json', json_encode([
            'from' => $this->from,
            'to' => $this->to,
            'migrations' => $this->migrations,
            'created_at' => $this->createdAt->format(\DATE_ATOM),
            'undone_at' => $this->undoneAt?->format(\DATE_ATOM),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    }

    public function undone(\DateTimeImmutable $at): self
    {
        return new self($this->directory, $this->from, $this->to, $this->migrations, $this->createdAt, $at);
    }

    /**
     * The last update's backup, while it can be undone: not undone already, younger than a week, and
     * of the update that brought the release that runs.
     */
    public static function undoable(string $backups, string $running, \DateTimeImmutable $now): ?self
    {
        $directories = glob($backups.'/*', \GLOB_ONLYDIR);
        $directories = false === $directories ? [] : $directories;
        rsort($directories);

        foreach ($directories as $directory) {
            $backup = self::load($directory);

            if (!$backup instanceof self) {
                continue;
            }

            // Only the newest one: an older update can't be undone past the newer one.
            return !$backup->undoneAt instanceof \DateTimeImmutable && $backup->to === $running && $backup->createdAt->add(new \DateInterval(self::UNDOABLE_FOR)) > $now ? $backup : null;
        }

        return null;
    }

    public static function load(string $directory): ?self
    {
        $content = @file_get_contents($directory.'/backup.json');

        if (false === $content || !is_file($directory.'/files.tar') || !is_file($directory.'/database.dump')) {
            return null;
        }

        try {
            $data = json_decode($content, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !\is_string($data['from'] ?? null) || !\is_string($data['to'] ?? null) || !\is_string($data['created_at'] ?? null) || !\is_array($data['migrations'] ?? null)) {
            return null;
        }

        $createdAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['created_at']);
        $undoneAt = \is_string($data['undone_at'] ?? null) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['undone_at']) : null;

        if (!$createdAt instanceof \DateTimeImmutable || false === $undoneAt) {
            return null;
        }

        return new self($directory, $data['from'], $data['to'], array_values(array_filter($data['migrations'], is_string(...))), $createdAt, $undoneAt);
    }
}
