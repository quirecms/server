<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

/**
 * Which snapshots a server made (D-36), signed with its key: the list of those it keeps, by restic's
 * snapshot ID. A snapshot's ID is a hash of all it holds (restic checks every piece against it when it
 * restores), so a signed ID vouches for every file in it. Backed up after each night's backups, in a
 * snapshot of its own: another machine restoring this server's backups reads the newest, and checks
 * it with the public key the owner kept.
 *
 * @phpstan-import-type Record from BackupLedger
 */
final class Provenance
{
    public const string TAG = 'quire-provenance';
    public const string FILE = 'provenance.json';

    /**
     * The signed list of a server's snapshots.
     *
     * @param array<string, Record> $records
     */
    public static function document(string $host, array $records, BackupSigning $signing, \DateTimeImmutable $now): string
    {
        $signed = json_encode([
            'schema' => 1,
            'host' => $host,
            'signed_at' => $now->format(\DATE_ATOM),
            'snapshots' => array_map(static fn (array $record): array => ['id' => $record['id'], 'kind' => $record['kind'], 'site' => $record['site'], 'created_at' => $record['created_at']], array_values($records)),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return json_encode(['key' => $signing->publicKey(), 'signed' => $signed, 'signature' => $signing->sign($signed)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT)."\n";
    }

    /**
     * The snapshots a document vouches for, by ID: only when one of the keys given signed it, for that
     * server.
     *
     * @param list<string> $keys The public keys trusted for that server
     *
     * @return array<string, array{kind: string, site: ?string, created_at: string}>
     *
     * @throws BackupFailed When it isn't one, or none of the keys signed it
     */
    public static function verify(string $document, string $host, array $keys): array
    {
        try {
            $outer = json_decode($document, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BackupFailed('The list of the backups the server made isn\'t one this server reads.');
        }

        if (!\is_array($outer) || !\is_string($outer['key'] ?? null) || !\is_string($outer['signed'] ?? null) || !\is_string($outer['signature'] ?? null)) {
            throw new BackupFailed('The list of the backups the server made isn\'t one this server reads.');
        }

        if (!\in_array($outer['key'], $keys, true) || !BackupSigning::verify($outer['signed'], $outer['signature'], $outer['key'])) {
            throw new BackupFailed(\sprintf('The list of the backups "%s" made isn\'t signed by its key%s.', $host, [] === $keys ? '' : ' ('.implode(', ', $keys).')'));
        }

        $signed = json_decode($outer['signed'], true, 5);

        if (!\is_array($signed) || 1 !== ($signed['schema'] ?? null) || $host !== ($signed['host'] ?? null) || !\is_array($signed['snapshots'] ?? null)) {
            throw new BackupFailed(\sprintf('The list of the backups "%s" made is signed, but not for it.', $host));
        }

        $vouched = [];

        foreach ($signed['snapshots'] as $snapshot) {
            if (\is_array($snapshot) && \is_string($snapshot['id'] ?? null) && 1 === preg_match('/^[0-9a-f]{64}\z/', $snapshot['id']) && \in_array($snapshot['kind'] ?? null, [BackupLedger::SITE, BackupLedger::SERVER], true) && \is_string($snapshot['created_at'] ?? null)) {
                $vouched[$snapshot['id']] = ['kind' => $snapshot['kind'], 'site' => \is_string($snapshot['site'] ?? null) ? $snapshot['site'] : null, 'created_at' => $snapshot['created_at']];
            }
        }

        return $vouched;
    }
}
