<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

/**
 * What a quire:backup file's manifest.json says of it (server contract 2): whether it's complete, and
 * each stored file it has no good copy of, with why ("unknown_disk", "missing", "damaged").
 *
 * The manifest is written by the site's code, in the site's container: it's read as untrusted. It's
 * size-limited, strictly typed, and what it names is only shown to people (cut short, without control
 * characters), never used as a path or a command.
 */
final readonly class BackupManifest
{
    /** Its entry in the backup's tar */
    public const string ENTRY = 'manifest.json';

    /** A manifest lists the site's tables and files' counts: more than this isn't one */
    public const int MAX_SIZE = 4_194_304;

    /** Absent files listed: the rest are counted */
    public const int LISTED = 25;

    private const int MAX_NAME = 512;

    /**
     * @param bool|null    $complete null: it doesn't say (server contract 1, whose exit code is 0 either way)
     * @param list<string> $absent   "<file> (<why>)", at most LISTED of them
     * @param int          $more     How many more it names
     */
    private function __construct(
        public ?bool $complete,
        public array $absent,
        public int $more,
    ) {
    }

    /**
     * @throws \UnexpectedValueException When it isn't a manifest this server reads
     */
    public static function parse(string $json): self
    {
        if (\strlen($json) > self::MAX_SIZE) {
            throw new \UnexpectedValueException('it\'s larger than any manifest is');
        }

        try {
            $manifest = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('it isn\'t JSON ('.$e->getMessage().')', 0, $e);
        }

        if (!\is_array($manifest) || array_is_list($manifest)) {
            throw new \UnexpectedValueException('it isn\'t a JSON object');
        }

        $complete = $manifest['complete'] ?? null;

        if (null !== $complete && !\is_bool($complete)) {
            throw new \UnexpectedValueException('its "complete" isn\'t true or false');
        }

        $entries = $manifest['absent'] ?? [];

        if (!\is_array($entries) || !array_is_list($entries)) {
            throw new \UnexpectedValueException('its "absent" isn\'t a list');
        }

        $absent = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\is_string($entry['file'] ?? null) || !\is_string($entry['reason'] ?? null)) {
                throw new \UnexpectedValueException('an entry of its "absent" isn\'t {file, reason}');
            }

            if (\count($absent) < self::LISTED) {
                $absent[] = \sprintf('%s (%s)', self::shown($entry['file']), self::shown($entry['reason']));
            }
        }

        // Incomplete with nothing named, or the other way round, isn't something it can mean.
        if ((false === $complete) !== ([] !== $entries)) {
            throw new \UnexpectedValueException(false === $complete ? 'it says it\'s incomplete but names no file' : 'it names absent files but doesn\'t say it\'s incomplete');
        }

        return new self($complete, $absent, \count($entries) - \count($absent));
    }

    /**
     * What it says is absent, for people: one file a line.
     */
    public function describe(): string
    {
        return implode("\n", [...$this->absent, ...($this->more > 0 ? [\sprintf('… and %d more', $this->more)] : [])]);
    }

    /**
     * Text of the site's, as it may be shown: no control characters (a terminal would act on them), and
     * cut short.
     */
    public static function shown(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1f\x7f]|\xc2[\x80-\x9f]/', '?', $text);

        return \strlen($text) > self::MAX_NAME ? mb_strcut($text, 0, self::MAX_NAME).'…' : $text;
    }
}
