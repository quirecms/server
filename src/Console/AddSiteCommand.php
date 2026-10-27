<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Git\Repository;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Operation\SiteRequest;
use Quire\Server\Site\Domain;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:add',
    description: 'Add a site: its own Quire, database and domains, behind the front door',
    help: <<<'HELP'
        Creates the site from quire/skeleton, gives it a database on the server's PostgreSQL, builds
        and starts it, installs it with its first admin, and opens its domains to visitors (their
        certificates come at the first visit, once their DNS points here):

          <info>quire-server site:add jane --domain=jane.example --domain=www.jane.example --admin-email=jane@example.com --title="Jane's notes"</info>

        With <comment>--invite</comment> the admin gets an email to choose their password (it needs an email server,
        QUIRE_SERVER_MAILER_DSN); otherwise a password is shown here once. If anything fails, what
        was done is undone, and the operation's log says why ("quire-server operations").

        <comment>Git mode</comment>, for developers: the site is a branch of your repository (a project made from
        quire/skeleton) instead of a fresh skeleton, and is deployed from it ("site:deploy", or by
        itself with --poll). Over SSH, create its deploy key first ("site:key"):

          <info>quire-server site:key blog</info>
          <info>quire-server site:add blog --git=git@github.com:me/blog.git --branch=main --poll --domain=blog.example --admin-email=me@example.com</info>
        HELP,
)]
final readonly class AddSiteCommand
{
    public function __construct(
        private SiteManager $sites,
        private string $defaultVersion,
    ) {
    }

    /**
     * @param list<string> $domain
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name on the server, e.g. "jane"')]
        string $name,
        #[Option(description: 'A domain of the site (repeatable); the first is the main one')]
        array $domain = [],
        #[Option(description: 'The first admin\'s email')]
        ?string $adminEmail = null,
        #[Option(description: 'The site\'s title')]
        ?string $title = null,
        #[Option(description: 'Email the admin a link to choose their password')]
        bool $invite = false,
        #[Option(description: 'The Quire version, e.g. "^0.1" (the server\'s default otherwise)')]
        ?string $quireVersion = null,
        #[Option(description: 'Git mode: the site\'s repository (git@host:path, ssh://… or https://…)')]
        ?string $git = null,
        #[Option(description: 'Git mode: the branch it follows')]
        string $branch = 'main',
        #[Option(description: 'Git mode: deploy new commits of the branch by itself')]
        bool $poll = false,
    ): int {
        try {
            if (null !== $git && null !== $quireVersion) {
                throw new \InvalidArgumentException('In Git mode, the Quire version is the repository\'s: leave out --quire-version.');
            }

            $request = new SiteRequest(
                new SiteName($name),
                array_map(static fn (string $value): Domain => new Domain($value), $domain),
                $title ?? $name,
                $adminEmail ?? throw new \InvalidArgumentException('Pass the first admin\'s email: --admin-email.'),
                $invite,
                $quireVersion ?? $this->defaultVersion,
                null === $git ? null : new Repository($git, $branch),
                null !== $git && $poll,
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->title(\sprintf('Adding the site "%s"', $request->name));

        try {
            $added = $this->sites->add($request, Requester::CHANNEL, Requester::name(), static fn (string $step) => $io->writeln('  '.$step.'…'));
        } catch (\Throwable $e) {
            $io->error(['Adding the site failed, and what was done is undone:', $e->getMessage()]);

            return Command::FAILURE;
        }

        $domains = array_map(static fn (Domain $domain): string => $domain->unicode(), $added->site->domains);
        $io->success(\sprintf('"%s" runs, at %s.', $added->site->name, implode(', ', $domains)));
        $io->writeln(\sprintf('Point the DNS of %s at this server: its certificate comes at the first visit.', implode(', ', $domains)));

        if (null !== $added->adminPassword) {
            $io->warning(\sprintf('The admin (%s) signs in with the password: %s', $request->adminEmail, $added->adminPassword));
            $io->writeln('It isn\'t shown or kept anywhere else. Change it after signing in.');
        } else {
            $io->writeln(\sprintf('%s got an email to choose a password.', $request->adminEmail));
        }

        return Command::SUCCESS;
    }
}
