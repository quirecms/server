<?php

declare(strict_types=1);

namespace Quire\Server\Console;

use Quire\Server\Operation\OperationLog;
use Quire\Server\ServerUpdate\ServerFolder;
use Quire\Server\ServerUpdate\ServerSettings;
use Quire\Server\ServerUpdate\ServerStack;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:settings:set',
    description: 'Change the server\'s settings (server.env), then apply them',
    help: <<<'HELP'
        quire-server settings:set NOTIFY_EMAIL=ops@example.com NAME=fra1-web-01
        quire-server settings:set MAILER_DSN          (a secret: asked for, not shown)
        printf '%s' "$dsn" | quire-server settings:set MAILER_DSN   (a secret from standard input)

        Names go with or without "QUIRE_SERVER_". Each value is checked as the server reads it, then
        server.env is written whole (root only) and the server starts again with it, as "quire-server
        up" does. If it doesn't start, the settings before are put back. A secret (the mailer's DSN,
        the webhook, its secret) is never taken from the command line, which would keep it in the
        shell's history and show it to other users of the machine. The operation log records who
        changed which settings, never their values.
        HELP,
)]
final readonly class SettingsSetCommand
{
    public const string ACTION = 'server.settings';

    public function __construct(
        private ServerFolder $folder,
        private ServerStack $stack,
        private OperationLog $operations,
        private bool $inUpdater,
    ) {
    }

    /**
     * @param list<string> $settings
     */
    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'NAME=value, or a secret\'s NAME alone (its value is asked for)')]
        array $settings,
    ): int {
        if (!$this->inUpdater) {
            $io->error('Settings are changed from the updater (it saves server.env): run "quire-server settings:set" on the machine.');

            return Command::FAILURE;
        }

        try {
            $changes = $this->changes($io, $input->isInteractive(), $settings);
        } catch (\InvalidArgumentException $e) {
            $io->error(['Nothing was changed.', $e->getMessage()]);

            return Command::FAILURE;
        }

        $before = $this->folder->env();
        $changed = array_keys(array_filter($changes, static fn (string $value, string $name): bool => $before->get($name) !== $value, \ARRAY_FILTER_USE_BOTH));

        if ([] === $changed) {
            $io->writeln('Nothing to change: the settings have those values already.');

            return Command::SUCCESS;
        }

        $operation = $this->operations->start(self::ACTION, null, ['settings' => $changed], 'cli', Requester::name());
        $kept = $this->folder->path.'/server.env.before-'.new \DateTimeImmutable()->format('Ymd-His');
        ServerFolder::writeSecret($kept, $before->content());
        $this->folder->writeEnv($before->with($changes));
        $operation->line('Changed: '.implode(', ', $changed));
        $io->writeln(\sprintf('  Saved in server.env (the settings before: %s). Starting the server with them…', $kept));

        try {
            $this->stack->up($this->folder, 300);
        } catch (\Throwable $e) {
            // The server as it was: its settings, and the containers started with them
            $this->folder->writeEnv($before);

            try {
                $this->stack->up($this->folder, 300);
            } catch (\Throwable) {
                // Said below: "quire-server up" again
            }

            $operation->fail($e);
            $io->error(['The server didn\'t start with the new settings, so the ones before are back.', $e->getMessage(), '"quire-server logs agent" says more.']);

            return Command::FAILURE;
        }

        $operation->succeed();
        $io->success(\sprintf('Changed %s, and the server runs with them.', implode(', ', $changed)));

        if ([] !== array_intersect($changed, ['QUIRE_SERVER_MAILER_DSN', 'QUIRE_SERVER_NOTIFY_EMAIL', 'QUIRE_SERVER_NOTIFY_FROM', 'QUIRE_SERVER_NOTIFY_WEBHOOK', 'QUIRE_SERVER_ACME_EMAIL'])) {
            $io->writeln('"quire-server notify:test" sends a test notification with them.');
        }

        if (\in_array('QUIRE_SERVER_MAILER_DSN', $changed, true)) {
            $io->writeln('Sites keep their own mailer: "quire-server site:mail <site> --server" gives one the server\'s.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $settings
     *
     * @return array<string, string> The new values, by full name
     *
     * @throws \InvalidArgumentException When one can't be changed, or its value isn't right
     */
    private function changes(SymfonyStyle $io, bool $interactive, array $settings): array
    {
        if ([] === $settings) {
            throw new \InvalidArgumentException('Name the settings: NAME=value, or a secret\'s NAME alone ("quire-server settings" lists them).');
        }

        $changes = [];

        foreach ($settings as $setting) {
            $parts = explode('=', $setting, 2);
            $given = $parts[0];
            $value = $parts[1] ?? null;
            $name = ServerSettings::name($given);
            $refusal = ServerSettings::refusal($name);

            if (null !== $refusal) {
                throw new \InvalidArgumentException($refusal);
            }

            if (ServerSettings::secret($name)) {
                if (null !== $value) {
                    throw new \InvalidArgumentException(\sprintf('%s is a secret: name it alone, without "=value", and give its value when asked (or on standard input), so it isn\'t kept in the shell\'s history.', $name));
                }

                $value = $this->secretValue($io, $interactive, $name);
            } elseif (null === $value) {
                throw new \InvalidArgumentException(\sprintf('Give %s a value: %s=… (an empty one clears it).', $name, $given));
            }

            $value = trim($value);
            $problem = ServerSettings::problem($name, $value);

            if (null !== $problem) {
                throw new \InvalidArgumentException($problem);
            }

            $changes[$name] = $value;
        }

        return $changes;
    }

    /**
     * Asked for, not shown; without a terminal (piped in), the first line of standard input.
     */
    private function secretValue(SymfonyStyle $io, bool $interactive, string $name): string
    {
        if (!$interactive) {
            $line = fgets(\STDIN);

            return false === $line ? '' : rtrim($line, "\r\n");
        }

        $value = $io->askHidden(\sprintf('%s (not shown; empty clears it)', $name));

        return \is_string($value) ? $value : '';
    }
}
