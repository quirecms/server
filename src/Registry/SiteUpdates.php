<?php

declare(strict_types=1);

namespace Quire\Server\Registry;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Quire\Server\Site\SiteName;

/**
 * Sites' updates, as they were done: only the last one of a site can be undone, within a week.
 */
final readonly class SiteUpdates
{
    public const string DONE = 'done';
    public const string UNDONE = 'undone';
    public const string SUPERSEDED = 'superseded';

    /** New packages (a managed site) */
    public const string UPDATE = 'update';
    /** A commit of its repository (Git mode) */
    public const string DEPLOY = 'deploy';

    /** How long an update can be undone */
    public const string UNDO_WINDOW = '7 days';

    public function __construct(
        private Connection $connection,
        private string $schema = Schema::NAME,
    ) {
    }

    /**
     * @param array{id: string, site: SiteName, operation_id: string, kind: string, quire_from: ?string, quire_to: ?string, packages: list<array{package: string, from: ?string, to: ?string}>, image_before: string, image_after: string, saved_files: string, skeleton_paths: list<string>, backup: string, migrations_ran: ?bool, commit_before: ?string, commit_after: ?string} $update
     */
    public function record(array $update): void
    {
        $this->connection->transactional(function () use ($update): void {
            // Only the last update can be undone: earlier ones can't any more.
            $this->connection->executeStatement(\sprintf("UPDATE %s.site_update SET status = 'superseded' WHERE site = ? AND status = 'done'", $this->schema()), [$update['site']->value]);
            $this->connection->insert($this->schema().'.site_update', [...$update, 'site' => $update['site']->value, 'status' => self::DONE], [
                'packages' => Types::JSON,
                'skeleton_paths' => Types::JSON,
                'migrations_ran' => Types::BOOLEAN,
            ]);
        });
    }

    /**
     * The site's last update, while it can still be undone.
     *
     * @return array{id: string, kind: string, quire_from: ?string, quire_to: ?string, packages: list<array{package: string, from: ?string, to: ?string}>, image_before: string, image_after: string, saved_files: string, skeleton_paths: list<string>, backup: string, migrations_ran: ?bool, commit_before: ?string, commit_after: ?string, created_at: string}|null
     */
    public function undoable(SiteName $site): ?array
    {
        $row = $this->connection->fetchAssociative(
            \sprintf("SELECT * FROM %s.site_update WHERE site = ? AND status = 'done' AND created_at > now() - interval '%s' ORDER BY created_at DESC LIMIT 1", $this->schema(), self::UNDO_WINDOW),
            [$site->value],
        );

        if (false === $row) {
            return null;
        }

        $packages = json_decode($this->text($row['packages']), true);
        $paths = json_decode($this->text($row['skeleton_paths']), true);

        return [
            'id' => $this->text($row['id']),
            'kind' => $this->text($row['kind']),
            'quire_from' => null === $row['quire_from'] ? null : $this->text($row['quire_from']),
            'quire_to' => null === $row['quire_to'] ? null : $this->text($row['quire_to']),
            'packages' => \is_array($packages) ? array_values(array_filter(array_map(static fn (mixed $change): ?array => \is_array($change) && \is_string($change['package'] ?? null)
                ? ['package' => $change['package'], 'from' => \is_string($change['from'] ?? null) ? $change['from'] : null, 'to' => \is_string($change['to'] ?? null) ? $change['to'] : null]
                : null, $packages))) : [],
            'image_before' => $this->text($row['image_before']),
            'image_after' => $this->text($row['image_after']),
            'saved_files' => $this->text($row['saved_files']),
            'skeleton_paths' => \is_array($paths) ? array_values(array_filter($paths, \is_string(...))) : [],
            'backup' => $this->text($row['backup']),
            'migrations_ran' => null === $row['migrations_ran'] ? null : (bool) $row['migrations_ran'],
            'commit_before' => null === $row['commit_before'] ? null : $this->text($row['commit_before']),
            'commit_after' => null === $row['commit_after'] ? null : $this->text($row['commit_after']),
            'created_at' => $this->text($row['created_at']),
        ];
    }

    public function markUndone(string $id): void
    {
        $this->connection->executeStatement(\sprintf("UPDATE %s.site_update SET status = 'undone', undone_at = now() WHERE id = ?", $this->schema()), [$id]);
    }

    private function schema(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
