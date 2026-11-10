<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Git\DeployKeys;
use Quire\Server\Site\SiteName;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:site:key',
    description: 'A site\'s deploy key, to read its Git repository (created the first time)',
    help: <<<'HELP'
        For a site that follows a Git repository over SSH: a key of its own, which can read that one
        repository and nothing else. Add the public key it prints to the repository as a deploy key,
        read-only (GitHub: Settings → Deploy keys; GitLab: Settings → Repository → Deploy keys), then
        add the site:

          <info>quire-server site:key blog</info>
          <info>quire-server site:add blog --git=git@github.com:me/blog.git --domain=blog.example --admin-email=me@example.com</info>

        --rotate makes a new key in its place (e.g. once a year, or after the server was copied): add
        the new one to the repository, and remove the old one there.

        The private key stays on this server, readable by root only, outside the site's folder. It's
        deleted when the site is removed.
        HELP,
)]
final readonly class KeyCommand
{
    public function __construct(
        private DeployKeys $keys,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The site\'s short name, e.g. "blog" (it may not exist yet)')]
        string $name,
        #[Option(description: 'Make a new key in place of the one it has')]
        bool $rotate = false,
    ): int {
        try {
            $site = new SiteName($name);
            $new = $rotate || !$this->keys->exists($site);
            $comment = self::comment($site);
            $public = $rotate ? $this->keys->rotate($site, $comment) : $this->keys->publicKey($site, $comment);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(match (true) {
            $rotate => \sprintf('A new deploy key for "%s". Add it to the repository as a read-only deploy key, and remove the old one there (until then, deploys are refused):', $site),
            $new => \sprintf('A deploy key for "%s". Add it to the repository as a read-only deploy key:', $site),
            default => \sprintf('The deploy key of "%s":', $site),
        });
        $io->newLine();
        $io->writeln($public, SymfonyStyle::OUTPUT_RAW);
        $io->newLine();

        return Command::SUCCESS;
    }

    /**
     * The key's name where it's added (a repository's deploy keys): "<server>:<site>", the server
     * being QUIRE_SERVER_NAME, or the machine's host name ("quire-server" passes it in: the agent's own
     * is its container's), so that the owner knows which server and site a key is for.
     */
    public static function comment(SiteName $site): string
    {
        $server = '';

        foreach (['QUIRE_SERVER_NAME', 'QUIRE_SERVER_HOST_NAME'] as $variable) {
            $value = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', trim((string) getenv($variable)));

            if ('' !== trim($value, '-')) {
                $server = substr(trim($value, '-'), 0, 63);

                break;
            }
        }

        return \sprintf('%s:%s', '' === $server ? 'quire-server' : $server, $site);
    }
}
