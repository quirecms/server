<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\BackupRecovery;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\Restic;
use Quire\Server\Backup\S3;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'server:backups:recover',
    description: 'Bring back backups off the server deleted since a time, from the versions a protected bucket keeps',
    help: <<<'HELP'
        When backups were deleted from the bucket (someone with its keys: "quire-server status" says
        so), and the bucket keeps what's deleted (Object Lock: "quire-server status" says whether it's
        protected), this makes every file of the repository as it was at that time again. Backups made
        since are kept. It needs the bucket's versions listed: the server's own key may not have that
        right, so another can be given for this only (never saved):

          <info>quire-server backups:recover --before=2026-11-04T01:00:00Z --dry-run</info>
          <info>QUIRE_SERVER_BACKUP_RECOVER_ACCESS_KEY=… QUIRE_SERVER_BACKUP_RECOVER_SECRET_KEY=… quire-server backups:recover --before=…</info>

        Then "quire-server backups:check" checks them, and the bucket should get new keys.
        HELP,
)]
final readonly class BackupsRecoverCommand
{
    public function __construct(
        private BackupSettings $settings,
        private Restic $restic,
        private HttpClientInterface $client,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'A time before the backups were deleted (ISO 8601, e.g. 2026-11-04T01:00:00Z)')]
        ?string $before = null,
        #[Option(description: 'Only say what it would bring back')]
        bool $dryRun = false,
        #[Option(description: 'Don\'t ask')]
        bool $yes = false,
    ): int {
        if (!$this->settings->configured()) {
            $io->error('Backups off the server aren\'t set up: "quire-server backups:setup".');

            return Command::FAILURE;
        }

        $time = null === $before ? false : \DateTimeImmutable::createFromFormat(\DATE_ATOM, str_replace('Z', '+00:00', $before));

        if (false === $time) {
            $io->error('Give a time before the backups were deleted: --before=2026-11-04T01:00:00Z.');

            return Command::INVALID;
        }

        $key = getenv('QUIRE_SERVER_BACKUP_RECOVER_ACCESS_KEY');
        $secret = getenv('QUIRE_SERVER_BACKUP_RECOVER_SECRET_KEY');
        $s3 = S3::of($this->settings, $this->client, \is_string($key) && '' !== $key ? $key : null, \is_string($secret) && '' !== $secret ? $secret : null);

        if (!$dryRun && !$yes && !$io->confirm(\sprintf('Make the backups off the server as they were at %s again (backups made since are kept)?', $time->format('Y-m-d H:i:s T')), false)) {
            $io->writeln('Nothing changed.');

            return Command::SUCCESS;
        }

        try {
            $outcome = new BackupRecovery($s3)->recover($time, $dryRun);

            if (!$dryRun && [] !== $outcome['restored']) {
                // The locks restic held then are back too
                $this->restic->unlock();
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('%s %d file(s) of the repository as they were then; %d made since kept as they are.', $dryRun ? 'Would bring back' : 'Brought back', \count($outcome['restored']), $outcome['newer']));

        foreach ($outcome['failed'] as $failed) {
            $io->writeln('<error>Not brought back:</error> '.$failed);
        }

        if (!$dryRun) {
            $io->writeln('"quire-server backups:check" checks them; "quire-server backups <site>" lists them. Then give the bucket new keys.');
        }

        return [] === $outcome['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
