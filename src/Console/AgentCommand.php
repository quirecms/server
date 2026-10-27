<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Registry\SiteRegistry;
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
        match the sites that run, and a heartbeat file says the agent is alive (for Docker's health check).
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

            for ($slept = 0; !$once && !$this->stopping && $slept < max(5, $interval); ++$slept) {
                sleep(1);
            }
        } while (!$once && !$this->stopping);

        return Command::SUCCESS;
    }
}
