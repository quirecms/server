<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\LocalBackups;
use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Backup\SigningKeyRecovery;
use Quire\Server\Docker\Builders;
use Quire\Server\Docker\Gate;
use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Git\GitPoller;
use Quire\Server\Network\SiteLinks;
use Quire\Server\Notify\Notifier;
use Quire\Server\Notify\Problems;
use Quire\Server\Notify\Watch;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Update\AutomaticUpdates;
use Quire\Server\Update\SiteUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'server:agent', description: 'The agent\'s own work: keeps the front door in line with the registry', hidden: true, help: <<<'HELP'
        Runs as the agent container's main process. When it starts, it waits for the Docker gate, removes
        what releases before the gate left (the docker-proxy, privileged builders) and gives each site its
        scope on the gate (and no other site one); operations a crash or a restart interrupted are marked
        failed. Then, every minute, the front door and PostgreSQL are put back on sites' links they're
        missing from (after they were made again), the front door's list of domains is made to
        match the sites that run, new commits of Git mode sites that poll are deployed, the nightly
        backups off the server are made when due, sites due for their nightly automatic updates get them, problems that need the owner are found and
        told (D-35: "quire-server status"), and a heartbeat file says the agent is alive (for
        Docker's health check; also at each step of a long deploy or update).
        HELP)]
final class AgentCommand
{
    public const string HEARTBEAT = '/tmp/quire-server-agent.heartbeat';

    private bool $stopping = false;

    public function __construct(
        private readonly SiteRegistry $registry,
        private readonly OperationLog $operations,
        private readonly DomainDirectory $frontDoor,
        private readonly GitPoller $poller,
        private readonly AutomaticUpdates $updates,
        private readonly OffsiteBackups $backups,
        private readonly SiteUpdater $sites,
        private readonly LocalBackups $local,
        private readonly SiteManager $manager,
        private readonly Gate $gate,
        private readonly Builders $builders,
        private readonly ServerPaths $paths,
        private readonly SiteLinks $links,
        private readonly Watch $watch,
        private readonly Problems $problems,
        private readonly Notifier $notifier,
        private readonly SigningKeyRecovery $signingKeys,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Seconds between rounds')]
        int $interval = 60,
        #[Option(description: 'Do one round and stop')]
        bool $once = false,
    ): int {
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(\SIGTERM, fn (): true => $this->stopping = true);
            pcntl_signal(\SIGINT, fn (): true => $this->stopping = true);
        }

        // A wrong setting stops the agent at once, rather than every night.
        $hour = $this->updates->hour();
        $io->writeln(null === $hour ? 'Automatic updates: off on this server' : \sprintf('Automatic updates: every night from %02d:00 UTC', $hour));
        $this->gate($io);
        $abandoned = $this->operations->abandonRunning();

        if ($abandoned > 0) {
            $io->warning(\sprintf('%d operation(s) were interrupted and are marked failed.', $abandoned));
        }

        // Sites an add cut short left being created: removed, their names and domains free again.
        foreach ($this->manager->removeUnfinished() as $site) {
            $io->writeln(\sprintf('Recovery: "%s" was being added when the agent stopped: removed (add it again)', $site));
        }

        // Sites a crash or a power cut stopped in the middle of a release: as they were before it.
        foreach ($this->sites->recoverInterrupted(static fn (string $line) => $io->writeln('Recovery: '.$line)) as $site => $outcome) {
            $io->writeln(\sprintf('Recovery: "%s": %s', $site, $outcome));
        }

        // The key that signs its backups, as an update of Quire Server 0.4.0 left it (removed): put back
        // before a night could need it. Its failure doesn't stop the agent: the night says the key is gone.
        try {
            $from = $this->signingKeys->recover();

            if (null !== $from) {
                $io->writeln(\sprintf('Recovery: the key that signs this server\'s backups was put back from %s (an update of Quire Server 0.4.0 had removed it)', $from));
            }
        } catch (\Throwable $e) {
            $io->warning('The key that signs this server\'s backups couldn\'t be put back: '.$e->getMessage());
        }

        do {
            $this->links($io);
            $changes = $this->frontDoor->reconcile($this->registry->servedDomains());

            foreach ($changes['added'] as $domain) {
                $io->writeln(\sprintf('Front door: opened %s', $domain));
            }

            foreach ($changes['removed'] as $domain) {
                $io->writeln(\sprintf('Front door: closed %s', $domain));
            }

            touch(self::HEARTBEAT);
            $this->poller->poll($this->alive(static fn (string $line) => $io->writeln('Git: '.$line)));
            $this->backUp($io);
            $this->expire($io);
            $this->updates->run(false, 'agent', $this->alive(static fn (string $line) => $io->writeln('Updates: '.$line)));
            $this->notify($io);
            touch(self::HEARTBEAT);

            for ($slept = 0; !$once && !$this->stopping && $slept < max(5, $interval); ++$slept) {
                sleep(1);
            }
        } while (!$once && !$this->stopping);

        return Command::SUCCESS;
    }

    /**
     * The Docker gate, before anything acts on Docker: its socket open, what earlier releases left
     * removed, and each site's scope as the registry says (and no other: a scope left by a site that's
     * gone would keep a socket that acts as it).
     */
    private function gate(SymfonyStyle $io): void
    {
        $this->gate->waitForTrusted();

        if ($this->gate->removeDockerProxy()) {
            $io->writeln('Docker: removed the docker-proxy of an earlier release (the gate replaces it)');
        }

        foreach ($this->builders->removeLegacy() as $builder) {
            $io->writeln(\sprintf('Docker: removed the privileged builder of an earlier release (%s)', $builder));
        }

        foreach ($this->builders->stopIdle() as $builder) {
            $io->writeln(\sprintf('Docker: stopped an idle builder (%s); it starts again for its site\'s next build', $builder));
        }

        $projects = [];

        foreach ($this->registry->all() as $site) {
            // A site whose add was cut short may have no folder yet: it has no scope until it does.
            if (is_dir($this->paths->site($site->name))) {
                try {
                    $projects[] = $this->manager->projectOf($site);
                } catch (\Throwable $e) {
                    $io->warning(\sprintf('Docker: "%s" has no scope on the gate: %s', $site->name, $e->getMessage()));
                }
            }
        }

        $scopes = $this->gate->reconcile($projects);

        foreach ($scopes['removed'] as $removed) {
            $io->writeln(\sprintf('Docker: closed the gate\'s socket of "%s" (no such site)', $removed));
        }

        foreach ($scopes['failed'] as $site => $why) {
            $io->warning(\sprintf('Docker: nothing can act as the site of project "%s" until this is fixed: %s', $site, $why));
        }
    }

    /**
     * Sites' links as they should be: the front door and PostgreSQL back on them after they were made
     * again. A failure is said and tried again at the next round; the rest of the round goes on.
     */
    private function links(SymfonyStyle $io): void
    {
        try {
            foreach ($this->links->reconcile($this->registry->all()) as $line) {
                $io->writeln('Links: '.$line);
            }
        } catch (\Throwable $e) {
            $io->writeln('Links: '.$e->getMessage());
        }
    }

    /**
     * The backups on the server older than LocalBackups::KEEP_DAYS, once a day.
     */
    private function expire(SymfonyStyle $io): void
    {
        try {
            if ($this->local->due()) {
                foreach ($this->local->expire('agent', 'automatic') as $removed) {
                    $io->writeln('Backups: removed '.$removed);
                }
            }
        } catch (\Throwable $e) {
            $io->writeln('Backups: removing old ones failed: '.$e->getMessage());
        }
    }

    /**
     * Tonight's backups off the server, when they're due (a failure is in their operation's log).
     */
    private function backUp(SymfonyStyle $io): void
    {
        try {
            if ($this->backups->due()) {
                $this->backups->nightly('agent', 'automatic', $this->alive(static fn (string $line) => $io->writeln('Backups: '.$line)));
            }
        } catch (\Throwable $e) {
            $io->writeln('Backups: '.$e->getMessage());
        }
    }

    /**
     * What needs the owner (D-35), found and told: what this round's jobs left included.
     */
    private function notify(SymfonyStyle $io): void
    {
        try {
            $this->problems->sync($this->watch->problems());

            foreach ($this->notifier->round() as $line) {
                $io->writeln('Notifications: '.$line);
            }
        } catch (\Throwable $e) {
            $io->writeln('Notifications: '.$e->getMessage());
        }
    }

    /**
     * The same output, saying the agent is alive at each line (a deploy or an update takes minutes).
     *
     * @param \Closure(string): void $say
     *
     * @return \Closure(string): void
     */
    private function alive(\Closure $say): \Closure
    {
        return static function (string $line) use ($say): void {
            touch(self::HEARTBEAT);
            $say($line);
        };
    }
}
