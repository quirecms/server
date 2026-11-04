<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Quire\Server\Notify\Checks;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Each night, after the backups (D-37): whether the bucket keeps what's deleted (BackupProtection),
 * and whether every backup this server made and hasn't forgotten is still there. A backup gone that
 * the server's own retention didn't forget is someone else's doing: the owner hears of it the same
 * night ("quire-server status"), with how to get it back.
 */
final readonly class BackupWatch
{
    public const string REPOSITORY = 'backups.repository';

    private const string RECOVER = 'If the bucket keeps what\'s deleted ("quire-server status" says whether it\'s protected), "quire-server backups:recover --before=<a time before it happened>" brings them back. Then give the bucket new keys: these may be in someone else\'s hands.';

    public function __construct(
        private BackupSettings $settings,
        private Restic $restic,
        private BackupLedger $ledger,
        private Checks $checks,
        private HttpClientInterface $client,
    ) {
    }

    /**
     * @param \Closure(string): void $log
     */
    public function nightly(\Closure $log): void
    {
        foreach ([$this->protection(...), $this->repository(...)] as $check) {
            try {
                $log($check());
            } catch (\Throwable $e) {
                $log('Checking the backups failed: '.$e->getMessage());
            }
        }
    }

    /**
     * @return string What it found
     */
    public function protection(): string
    {
        $result = new BackupProtection(S3::of($this->settings, $this->client))->probe();
        $this->checks->record(BackupProtection::CHECK, !$result['protected'], $result['summary'], $result['advice']);

        return $result['summary'];
    }

    /**
     * @return string What it found
     */
    public function repository(): string
    {
        $kept = $this->ledger->kept($this->settings->repository, $this->settings->host);

        if (!$this->restic->opens()) {
            $summary = \sprintf('The backup repository is gone from the bucket%s.', [] === $kept ? '' : \sprintf(', with the %d backups this server made', \count($kept)));
            $this->checks->record(self::REPOSITORY, true, $summary, self::RECOVER);

            return $summary;
        }

        $listed = array_column($this->restic->snapshots([], $this->settings->host), 'id');
        $missing = array_values(array_diff(array_keys($kept), $listed));

        if ([] === $missing) {
            $summary = \sprintf('Every backup this server made and keeps is there (%d).', \count($kept));
            $this->checks->record(self::REPOSITORY, false, $summary);

            return $summary;
        }

        $newest = max(array_map(static fn (string $id): string => $kept[$id]['created_at'], $missing));
        $summary = \sprintf('%d of the backups this server made are gone from the bucket (the newest of them from %s), and its own retention didn\'t forget them.', \count($missing), new \DateTimeImmutable($newest)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC');
        $this->checks->record(self::REPOSITORY, true, $summary, self::RECOVER);

        return $summary;
    }
}
