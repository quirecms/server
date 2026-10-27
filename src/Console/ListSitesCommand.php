<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Docker\Compose;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'server:site:list', description: 'List the server\'s sites, their domains and whether they run')]
final readonly class ListSitesCommand
{
    public function __construct(
        private SiteRegistry $registry,
        private SiteManager $sites,
        private Compose $compose,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Include removed sites')]
        bool $removed = false,
    ): int {
        $all = $this->registry->all($removed);

        if ([] === $all) {
            $io->note('No sites yet. Add one with "quire-server site:add".');

            return Command::SUCCESS;
        }

        $io->table(['Site', 'Domains', 'Status', 'Containers', 'Since'], array_map(fn (Site $site): array => [
            $site->name->value,
            implode("\n", array_map(static fn (Domain $domain): string => $domain->unicode(), $site->domains)),
            $site->status,
            Site::RUNNING === $site->status ? $this->containers($site) : '',
            $site->createdAt->format('Y-m-d H:i'),
        ], $all));

        return Command::SUCCESS;
    }

    private function containers(Site $site): string
    {
        $states = array_map(
            static fn (array $container): string => \sprintf('%s %s%s', $container['service'], $container['state'], '' === $container['health'] ? '' : ' ('.$container['health'].')'),
            $this->compose->ps($this->sites->project($site->name, $site->domains)),
        );

        return [] === $states ? 'none' : implode("\n", $states);
    }
}
