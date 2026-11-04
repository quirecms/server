<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\BackupSigning;
use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Notify\Certificates;
use Quire\Server\Notify\Notifier;
use Quire\Server\Notify\Problems;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Quire\Server\Update\AutomaticUpdates;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:status',
    description: 'The server at a glance: what needs you, its nightly work, its sites and their certificates',
    help: <<<'HELP'
        Problems that need you come first, with what to do: failed nightly work (backups, automatic
        updates, deploys), certificates that don't come or aren't renewed, a disk filling up, and
        notifications that couldn't be sent. Each is told once when it starts (email, webhook: see
        QUIRE_SERVER_NOTIFY_* in server.env), again each week while it lasts, and once when it's over.
        --dismiss=<key> stops that for one you know about, until it's over or happens again.
        HELP,
)]
final readonly class StatusCommand
{
    /** The agent's rounds are a minute apart: one this late is stuck, or the agent isn't running */
    private const int HEARTBEAT_LATE = 300;

    public function __construct(
        private Problems $problems,
        private Notifier $notifier,
        private SiteRegistry $registry,
        private OperationLog $operations,
        private BackupSettings $backups,
        private AutomaticUpdates $updates,
        private Certificates $certificates,
        private BackupSigning $signing,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Stop telling of a problem, by its key (until it\'s over or happens again)')]
        ?string $dismiss = null,
    ): int {
        if (null !== $dismiss) {
            if (!$this->problems->dismiss($dismiss)) {
                $io->error(\sprintf('There\'s no problem "%s" now: "quire-server status" lists them with their keys.', $dismiss));

                return Command::FAILURE;
            }

            $io->success(\sprintf('"%s" isn\'t told or shown any more, until it\'s over or happens again.', $dismiss));

            return Command::SUCCESS;
        }

        $this->agent($io);
        $this->problemsSection($io);
        $this->notifications($io);
        $this->nightly($io);
        $this->sites($io);

        return Command::SUCCESS;
    }

    private function agent(SymfonyStyle $io): void
    {
        $beat = @filemtime(AgentCommand::HEARTBEAT);
        $age = false === $beat ? null : time() - $beat;

        $io->writeln(null !== $age && $age < self::HEARTBEAT_LATE
            ? \sprintf('Agent: running (its last round %d s ago)', $age)
            : '<error>Agent: not doing its rounds</error> (nothing is checked or told): "quire-server logs agent" says why, "quire-server up" starts it again.');
    }

    private function problemsSection(SymfonyStyle $io): void
    {
        $open = $this->problems->open();
        $dismissed = \count($this->problems->open(true)) - \count($open);
        $io->section([] === $open ? 'No problems' : \sprintf('%d %s', \count($open), 1 === \count($open) ? 'problem needs you' : 'problems need you'));

        foreach ($open as $stored) {
            $problem = $stored['problem'];
            $io->writeln(\sprintf('<comment>%s</comment> (since %s, key %s)', $problem->summary, $this->when($stored['since']), $problem->key));
            $io->writeln('  '.$problem->advice);
            $io->writeln(null === $stored['notified_at'] ? '  Not told yet.' : '  Told '.$this->when($stored['notified_at']).'.');
            $io->newLine();
        }

        if ($dismissed > 0) {
            $io->writeln(\sprintf('(%d dismissed, still there.)', $dismissed));
        }
    }

    private function notifications(SymfonyStyle $io): void
    {
        $io->section('Notifications');

        if (!$this->notifier->configured()) {
            $io->writeln('<comment>Nobody is told of problems:</comment> set QUIRE_SERVER_NOTIFY_EMAIL (with QUIRE_SERVER_MAILER_DSN) or QUIRE_SERVER_NOTIFY_WEBHOOK in server.env, then "quire-server up".');

            return;
        }

        foreach ($this->notifier->channels() as $channel) {
            $io->writeln('To: '.$channel);
        }

        $io->writeln('"quire-server notify:test" sends a test.');
    }

    private function nightly(SymfonyStyle $io): void
    {
        $io->section('Every night');
        $hour = $this->updates->hour();
        $io->writeln(null === $hour ? 'Automatic updates: off on this server' : \sprintf('Automatic updates: from %02d:00 UTC (each site\'s own: "quire-server site:updates")', $hour));

        if (!$this->backups->configured()) {
            $io->writeln('<comment>Backups off the server: none</comment> ("quire-server backups:setup" sets them up)');

            return;
        }

        $last = array_find($this->operations->latest(null, 200), static fn (array $operation): bool => OffsiteBackups::NIGHTLY === $operation['action']);
        $io->writeln(null === $last
            ? \sprintf('Backups off the server: from %02d:00 UTC, none made yet', (int) $this->backups->hour)
            : \sprintf('Backups off the server: last %s, %s (%s)', $this->when(new \DateTimeImmutable($last['started_at'])), $last['status'], $last['id']));

        if ($this->signing->exists()) {
            $io->writeln('Their public key (keep it with their password: restoring on another machine checks it): '.$this->signing->publicKey());
        }
    }

    private function sites(SymfonyStyle $io): void
    {
        $sites = $this->registry->all();
        $io->section(\sprintf('%d %s', \count($sites), 1 === \count($sites) ? 'site' : 'sites'));
        $rows = [];

        foreach ($sites as $site) {
            $rows[] = [
                $site->name->value,
                $site->status,
                implode("\n", array_map(fn (Domain $domain): string => $domain->unicode().(Site::RUNNING === $site->status ? '  '.$this->certificate($domain) : ''), $site->domains)),
            ];
        }

        if ([] !== $rows) {
            $io->table(['Site', 'Status', 'Domains and certificates'], $rows);
        }
    }

    private function certificate(Domain $domain): string
    {
        $certificate = $this->certificates->of($domain->value);

        return null === $certificate ? 'no certificate yet' : 'certificate until '.$certificate['not_after']->format('Y-m-d');
    }

    private function when(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';
    }
}
