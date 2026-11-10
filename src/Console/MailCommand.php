<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Operation\SiteManager;
use Quire\Server\ServerUpdate\ServerSettings;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:mail',
    description: 'Show how a site sends email, or give it another mailer',
    help: <<<'HELP'
        The mailer a site sends its emails with (invitations, password resets): MAILER_DSN in its
        prod.env, shown without its password or token. A site gets the server's when it's added, and
        keeps its own after.

          <info>quire-server site:mail jane</info>
          <info>quire-server site:mail jane --server</info>   the server's mailer (QUIRE_SERVER_MAILER_DSN)
          <info>quire-server site:mail jane --set</info>      another one: asked for, not shown (or on standard input)
          <info>quire-server site:mail jane --none</info>     no email at all

        The site is started again with it, a few seconds offline, and put back as it was if it doesn't
        start. A mailer's password is never taken from the command line, nor logged. Who the emails
        come from is the site's setting: in its admin, Site → Settings, "Email sender".
        HELP,
)]
final readonly class MailCommand
{
    public function __construct(
        private SiteManager $sites,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'The site\'s name')]
        string $site,
        #[Option(description: 'Give it the server\'s mailer')]
        bool $server = false,
        #[Option(description: 'Give it another mailer (asked for, or on standard input)')]
        bool $set = false,
        #[Option(description: 'No mailer: the site sends no email')]
        bool $none = false,
    ): int {
        try {
            $name = new SiteName($site);
            $current = $this->sites->mailer($name);

            if (null === $current) {
                throw new \DomainException(\sprintf('"%s" has no prod.env of the server\'s, so there\'s no mailer to show (is it a site of this server? "quire-server site:list").', $name));
            }

            if (\count(array_filter([$server, $set, $none])) > 1) {
                throw new \DomainException('Choose one: --server, --set or --none.');
            }

            if (!$server && !$set && !$none) {
                $io->writeln(\sprintf('%s sends email with: %s', $name, '' === $current ? 'nothing (no mailer: it sends no email)' : ServerSettings::dsnShown($current)));

                if ('' !== $current && $current === $this->sites->serverMailer()) {
                    $io->writeln('That\'s the server\'s mailer.');
                }

                return Command::SUCCESS;
            }

            [$dsn, $source] = match (true) {
                $server => [$this->sites->serverMailer(), 'server'],
                $none => ['', 'none'],
                default => [$this->asked($io, $input->isInteractive()), 'own'],
            };

            if ($set && '' === $dsn) {
                throw new \DomainException('No mailer was given: nothing changed (--none is for no email at all).');
            }

            if ($server && '' === $dsn) {
                throw new \DomainException('The server has no mailer (QUIRE_SERVER_MAILER_DSN): "quire-server settings:set MAILER_DSN" sets one.');
            }

            $problem = '' === $dsn ? null : ServerSettings::problem('QUIRE_SERVER_MAILER_DSN', $dsn);

            if (null !== $problem) {
                throw new \DomainException(str_replace('QUIRE_SERVER_MAILER_DSN', 'The mailer', $problem));
            }

            $this->sites->setMailer($name, $dsn, $source, 'cli', Requester::name(), static fn (string $line) => $io->writeln('  '.$line.'…'));
        } catch (\InvalidArgumentException|\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(match ($source) {
            'server' => \sprintf('%s sends email with the server\'s mailer.', $name),
            'none' => \sprintf('%s sends no email now.', $name),
            default => \sprintf('%s sends email with %s.', $name, ServerSettings::dsnShown($dsn)),
        });

        return Command::SUCCESS;
    }

    private function asked(SymfonyStyle $io, bool $interactive): string
    {
        if (!$interactive) {
            $line = fgets(\STDIN);

            return false === $line ? '' : trim($line);
        }

        $value = $io->askHidden('The mailer, e.g. smtp://user:password@smtp.example.com:587 (not shown)');

        return \is_string($value) ? trim($value) : '';
    }
}
