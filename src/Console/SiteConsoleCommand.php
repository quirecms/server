<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Operation\SiteManager;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:console',
    description: 'Run one of a site\'s own commands (its bin/console), e.g. to list its users',
    help: <<<'HELP'
        A command of the site's Quire, run in its web container, as "bin/console" there:

          <info>quire-server site:console jane quire:user:list</info>
          <info>quire-server site:console jane -- quire:settings:set site.title "Jane's notes"</info>   ("--" before options of the site's command)
          <info>quire-server site:console jane list</info>      what it can run

        It runs without questions (--no-interaction is added). The operation log records who ran which
        command, not its arguments or what it printed, which can hold passwords.
        HELP,
)]
final readonly class SiteConsoleCommand
{
    public function __construct(
        private SiteManager $sites,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Argument(description: 'The site\'s name')]
        string $site,
        #[Argument(description: 'The site\'s command and its arguments')]
        array $arguments,
    ): int {
        try {
            return $this->sites->console(new SiteName($site), $arguments, 'cli', Requester::name(), static fn (string $chunk) => $output->write($chunk, false, OutputInterface::OUTPUT_RAW));
        } catch (\InvalidArgumentException|\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
