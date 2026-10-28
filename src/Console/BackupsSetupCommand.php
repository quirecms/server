<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Backup\BackupFailed;
use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\Restic;
use Quire\Server\Process\ProcessRunner;
use Quire\Server\ServerUpdate\ServerFolder;
use Quire\Server\ServerUpdate\ServerStack;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:backups:setup',
    description: 'Set up backups off the server: where they go (S3-compatible storage) and the password they\'re encrypted with',
    help: <<<'HELP'
        Asks for an S3-compatible bucket (DigitalOcean Spaces, Backblaze B2, Hetzner, AWS…) and a key
        for it, checks they work, then either creates the backup repository there, with a new password
        it shows you once, or connects to an existing one (a server that replaces another) with its
        password. Saves them in server.env (root only) and restarts the agent; from then on, every
        site and the server are backed up there every night.

        KEEP THE PASSWORD, AND THE KEY, SOMEWHERE ELSE (a password manager): without them, backups
        can't be read, and a server that's lost takes its copy of them with it.

          <info>quire-server backups:setup</info>
          <info>quire-server backups:setup --repository=s3:https://fra1.digitaloceanspaces.com/my-backups/quire --access-key=… --existing</info>

        The secret key and an existing password are asked for (never in arguments); automation can
        give them as QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY and QUIRE_SERVER_BACKUP_SETUP_PASSWORD.
        HELP,
)]
final readonly class BackupsSetupCommand
{
    public function __construct(
        private ProcessRunner $runner,
        private ServerFolder $folder,
        private ServerStack $stack,
        private BackupSettings $current,
        private string $cache,
        private bool $inUpdater,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The bucket, as restic names it, e.g. "s3:https://fra1.digitaloceanspaces.com/my-backups/quire"')]
        ?string $repository = null,
        #[Option(description: 'The bucket\'s access key ID')]
        ?string $accessKey = null,
        #[Option(description: 'The bucket\'s region, e.g. "fra1" (DigitalOcean), "eu-central-003" (Backblaze), "eu-central-1" (AWS)')]
        ?string $region = null,
        #[Option(description: 'Connect to an existing repository (its password is asked for), instead of creating one')]
        bool $existing = false,
        #[Option(description: 'This server\'s name in the backups (e.g. when several share a bucket)')]
        string $host = 'quire-server',
        #[Option(description: 'Don\'t ask (the secret key and password from the environment)')]
        bool $yes = false,
    ): int {
        if (!$this->inUpdater) {
            $io->error('Backups are set up from the updater (it saves server.env): run "quire-server backups:setup" on the machine.');

            return Command::FAILURE;
        }

        $io->title('Backups off the server');

        if ($this->current->configured()) {
            $io->note(\sprintf('Backups go to %s now: this replaces that.', $this->current->repository));
        }

        try {
            $repository ??= $this->ask($io, $yes, 'The bucket, e.g. s3:https://fra1.digitaloceanspaces.com/my-backups/quire');
            $accessKey ??= $this->ask($io, $yes, 'Its access key ID');
            $region ??= $yes ? 'us-east-1' : $this->ask($io, false, 'Its region, e.g. fra1, eu-central-003, eu-central-1 (us-east-1 if it has none)');
            $secretKey = $this->secret('QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY') ?? $this->askHidden($io, $yes, 'Its secret key (not shown)');
            $password = $existing
                ? $this->secret('QUIRE_SERVER_BACKUP_SETUP_PASSWORD') ?? $this->askHidden($io, $yes, 'The repository\'s password (not shown)')
                : $this->secret('QUIRE_SERVER_BACKUP_SETUP_PASSWORD') ?? rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
            $settings = new BackupSettings($repository, $accessKey, $secretKey, $password, '' === $region ? 'us-east-1' : $region, $host);
            $settings->validate();
            $restic = new Restic($this->runner, $settings, $this->cache);
            $io->writeln('  Checking the bucket…');
            $opens = $restic->opens();

            if ($existing && !$opens) {
                throw new BackupFailed('There\'s no backup repository there: leave out --existing to create one.');
            }

            if (!$existing && $opens) {
                throw new BackupFailed('There\'s a backup repository there already: connect to it with --existing (and its password), or choose another folder.');
            }

            if (!$existing) {
                $io->writeln('  Creating the backup repository (encrypted with the new password)…');
                $restic->init();
            }
        } catch (\InvalidArgumentException|BackupFailed $e) {
            $io->error(['Nothing was saved.', $e->getMessage()]);

            return Command::FAILURE;
        }

        if ($settings->plainHttp()) {
            $io->warning('The bucket is reached over plain HTTP: the backups are encrypted, but its keys travel in the clear. Use https:// unless it\'s on this machine.');
        }

        $this->folder->writeEnv($this->folder->env()->with([
            'QUIRE_SERVER_BACKUP_REPOSITORY' => $settings->repository,
            'QUIRE_SERVER_BACKUP_ACCESS_KEY' => $settings->accessKey,
            'QUIRE_SERVER_BACKUP_SECRET_KEY' => $settings->secretKey,
            'QUIRE_SERVER_BACKUP_PASSWORD' => $settings->password,
            'QUIRE_SERVER_BACKUP_REGION' => $settings->region,
            'QUIRE_SERVER_BACKUP_HOST' => $settings->host,
        ]));
        $io->writeln('  Saved in server.env (root only). Restarting the agent…');

        try {
            $this->stack->up($this->folder, 300);
        } catch (\Throwable $e) {
            $io->error(['Saved, but the server didn\'t start again: "quire-server up" and "quire-server logs agent".', $e->getMessage()]);

            return Command::FAILURE;
        }

        if (!$existing && null === $this->secret('QUIRE_SERVER_BACKUP_SETUP_PASSWORD')) {
            $io->warning([
                'The backups\' password (shown only now): '.$password,
                'Keep it, with the bucket\'s keys, somewhere other than this server (a password manager). Without it, the backups can\'t be read: if this server is lost, it\'s the only way back.',
            ]);
        }

        $io->success('Backups off the server are set up: every night from '.$this->current->hour.':00 UTC. "quire-server backups:now" makes the first one now.');

        return Command::SUCCESS;
    }

    private function secret(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function ask(SymfonyStyle $io, bool $yes, string $question): string
    {
        if ($yes) {
            throw new \InvalidArgumentException($question.': give it as an option.');
        }

        $answer = $io->ask($question);

        return \is_string($answer) ? trim($answer) : '';
    }

    private function askHidden(SymfonyStyle $io, bool $yes, string $question): string
    {
        if ($yes) {
            throw new \InvalidArgumentException($question.': give it in the environment.');
        }

        $answer = $io->askHidden($question);

        return \is_string($answer) ? $answer : '';
    }
}
