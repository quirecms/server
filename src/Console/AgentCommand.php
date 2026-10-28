<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Git\GitPoller;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Update\AutomaticUpdates;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:agent',
    description: 'The agent\'s own work: keeps the front door in line with the registry',
    help: <<<'HELP'
        Runs as the agent container's main process. When it starts, operations a crash or a restart
        interrupted are marked failed. Then, every minute, the front door's list of domains is made to
        match the sites that run, new commits of Git mode sites that poll are deployed, the nightly
        backups off the server are made when due, sites due for their nightly automatic updates get them, and a heartbeat file says the agent is alive (for
        Docker's health check; also at each step of a long deploy or update).
        HELP,
)]
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
        $abandoned = $this->operations->abandonRunning();

        if ($abandoned > 0) {
            $io->warning(\sprintf('%d operation(s) were interrupted and are marked failed.', $abandoned));
        }

        do {
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
            $this->updates->run(false, 'agent', $this->alive(static fn (string $line) => $io->writeln('Updates: '.$line)));
            touch(self::HEARTBEAT);

            for ($slept = 0; !$once && !$this->stopping && $slept < max(5, $interval); ++$slept) {
                sleep(1);
            }
        } while (!$once && !$this->stopping);

        return Command::SUCCESS;
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
