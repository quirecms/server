<?php

declare(strict_types=1);

namespace Quire\Server\ServerDatabase;

use Quire\Server\ServerUpdate\ServerFolder;

/**
 * An upgrade of the server's PostgreSQL to a newer major (/srv/quire/backups/database/<time>/upgrade.json),
 * written before each step that changes something: an upgrade that was cut short (the machine
 * restarted, the updater was killed) is found, and the server put back as it was, by the next run.
 */
final readonly class DatabaseUpgrade
{
    /** Copying into the new major, beside the old one (which still runs) */
    public const string COPYING = 'copying';

    /** server.env names the new major: it starts, and is checked */
    public const string SWITCHED = 'switched';

    /** The new major runs, the old one's data is kept (the way back) */
    public const string DONE = 'done';

    /** Going back to the old major's data */
    public const string UNDOING = 'undoing';

    public const string UNDONE = 'undone';

    /** It failed, or was cut short, and the server runs the old major as it was */
    public const string FAILED = 'failed';

    /** How long an upgrade can be undone */
    public const string UNDOABLE_FOR = 'P7D';

    /**
     * @param list<string> $stopped The containers stopped while it ran (sites using the server's PostgreSQL), started again after
     */
    public function __construct(
        public string $directory,
        public int $from,
        public int $to,
        public string $state,
        public array $stopped,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $changedAt = null,
    ) {
    }

    /**
     * Whether it was cut short: the server may run neither major as it should.
     */
    public function unfinished(): bool
    {
        return \in_array($this->state, [self::COPYING, self::SWITCHED, self::UNDOING], true);
    }

    public function undoable(\DateTimeImmutable $now): bool
    {
        return self::DONE === $this->state && $this->createdAt->add(new \DateInterval(self::UNDOABLE_FOR)) > $now;
    }

    /**
     * @param list<string>|null $stopped
     */
    public function with(string $state, ?array $stopped = null): self
    {
        $upgrade = new self($this->directory, $this->from, $this->to, $state, $stopped ?? $this->stopped, $this->createdAt, new \DateTimeImmutable());
        $upgrade->save();

        return $upgrade;
    }

    public function save(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('%s can\'t be made.', $this->directory));
        }

        ServerFolder::writeSecret($this->directory.'/upgrade.json', json_encode([
            'from' => $this->from,
            'to' => $this->to,
            'state' => $this->state,
            'stopped' => $this->stopped,
            'created_at' => $this->createdAt->format(\DATE_ATOM),
            'changed_at' => $this->changedAt?->format(\DATE_ATOM),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * Where upgrades are written down, on a server with that root (/srv/quire).
     */
    public static function directory(string $root): string
    {
        return rtrim($root, '/').'/backups/database';
    }

    /**
     * Whether PostgreSQL's major changed after that moment (or may have: an upgrade cut short): the
     * server's files and settings from before it would start an older major's data, out of date.
     */
    public static function changedSince(string $root, \DateTimeImmutable $moment): bool
    {
        $latest = self::latest(self::directory($root));

        return $latest instanceof self && ($latest->changedAt ?? $latest->createdAt) > $moment && self::FAILED !== $latest->state;
    }

    /**
     * The newest upgrade (in the order they're made), or null when there's none.
     */
    public static function latest(string $upgrades): ?self
    {
        $directories = glob($upgrades.'/*', \GLOB_ONLYDIR);
        $directories = false === $directories ? [] : $directories;
        rsort($directories);

        foreach ($directories as $directory) {
            $upgrade = self::load($directory);

            if ($upgrade instanceof self) {
                return $upgrade;
            }
        }

        return null;
    }

    public static function load(string $directory): ?self
    {
        $content = @file_get_contents($directory.'/upgrade.json');

        if (false === $content) {
            return null;
        }

        try {
            $data = json_decode($content, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $states = [self::COPYING, self::SWITCHED, self::DONE, self::UNDOING, self::UNDONE, self::FAILED];

        if (!\is_array($data) || !\is_int($data['from'] ?? null) || !\is_int($data['to'] ?? null) || !\in_array($data['state'] ?? null, $states, true) || !\is_array($data['stopped'] ?? null) || !\is_string($data['created_at'] ?? null)) {
            return null;
        }

        $createdAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['created_at']);
        $changedAt = \is_string($data['changed_at'] ?? null) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['changed_at']) : null;

        if (!$createdAt instanceof \DateTimeImmutable || false === $changedAt) {
            return null;
        }

        return new self($directory, $data['from'], $data['to'], $data['state'], array_values(array_filter($data['stopped'], is_string(...))), $createdAt, $changedAt);
    }
}
