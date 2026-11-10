<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Console;

use Quire\Server\Console\SettingsCommand;
use Quire\Server\Console\SettingsSetCommand;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Process\ProcessResult;
use Quire\Server\ServerUpdate\ServerFolder;
use Quire\Server\ServerUpdate\ServerSettings;
use Quire\Server\ServerUpdate\ServerStack;
use Quire\Server\Tests\ScriptedRunner;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * An owner reads and changes server.env without root's editor (OWNER-12): secrets are never shown,
 * never taken from the command line, never logged; a change the server can't start with is undone.
 */
final class SettingsCommandsTest extends RegistryTestCase
{
    private const string BEFORE = "QUIRE_SERVER_APP_SECRET=0123456789abcdef0123456789abcdef\n"
        ."QUIRE_SERVER_MAILER_DSN='smtp://darko%40example.com:app-password@smtp.gmail.com:587'\n"
        ."QUIRE_SERVER_NOTIFY_EMAIL=darko@example.com\n"
        ."QUIRE_SERVER_NOTIFY_WEBHOOK='https://hooks.example.com/T000/B000/xyz'\n"
        ."QUIRE_SERVER_QUIRE_VERSION=^0.1\n"
        ."QUIRE_SERVER_IMAGE='ghcr.io/quirecms/server@sha256:".'aaaa'."'\n";

    private string $root;

    private ScriptedRunner $runner;

    /** Whether "docker compose up" fails, as with settings the server can't start with */
    private bool $upFails = false;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/quire-settings-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/server/server.env', self::BEFORE);
        $this->runner = new ScriptedRunner([
            'docker compose up' => fn (array $command): ProcessResult => $this->upFails && str_contains((string) file_get_contents($this->root.'/server/server.env'), 'ops@example.com')
                ? new ProcessResult($command, 1, '', 'agent is unhealthy')
                : ScriptedRunner::ok($command),
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testSettingsAreShownWithoutTheirSecrets(): void
    {
        $tester = $this->tester(new SettingsCommand(new ServerFolder($this->runner, $this->root), true));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $shown = $tester->getDisplay();
        self::assertStringContainsString('smtp://darko@example.com:(password)@smtp.gmail.com:587', $shown);
        self::assertStringNotContainsString('app-password', $shown);
        self::assertStringNotContainsString('0123456789abcdef', $shown);
        self::assertStringNotContainsString('hooks.example.com', $shown, 'A webhook\'s address is its key.');
        self::assertStringContainsString('set (not shown)', $shown);
        self::assertStringContainsString('darko@example.com', $shown);
    }

    public function testSettingsAreChangedCheckedAppliedAndLoggedWithoutValues(): void
    {
        $tester = $this->tester($this->setter());

        self::assertSame(Command::SUCCESS, $tester->execute(['settings' => ['NOTIFY_EMAIL=ops@example.com', 'quire_version=^0.2', 'QUIRE_SERVER_NAME=fra1-web-01']]), $tester->getDisplay());
        $env = new ServerFolder($this->runner, $this->root)->env();
        self::assertSame('ops@example.com', $env->get('QUIRE_SERVER_NOTIFY_EMAIL'));
        self::assertSame('^0.2', $env->get('QUIRE_SERVER_QUIRE_VERSION'));
        self::assertSame('fra1-web-01', $env->get('QUIRE_SERVER_NAME'));
        self::assertStringContainsString('app-password', (string) $env->get('QUIRE_SERVER_MAILER_DSN'), 'The rest is kept.');
        self::assertNotEmpty(glob($this->root.'/server/server.env.before-*'), 'The settings before are kept.');
        self::assertSame(0o600, fileperms($this->root.'/server/server.env') & 0o777);
        self::assertTrue(\in_array('docker compose up', $this->runner->keys(), true));

        $logged = (string) json_encode($this->connection->fetchAllAssociative(\sprintf('SELECT o.parameters, l.line FROM %1$s.operation o LEFT JOIN %1$s.operation_log l ON l.operation_id = o.id WHERE o.action = ?', $this->schema), [SettingsSetCommand::ACTION]));
        self::assertStringContainsString('QUIRE_SERVER_NOTIFY_EMAIL', $logged);
        self::assertStringNotContainsString('ops@example.com', $logged, 'Names, not values.');
    }

    public function testASecretIsAskedForNeverTakenFromTheCommandLine(): void
    {
        $tester = $this->tester($this->setter());

        self::assertSame(Command::FAILURE, $tester->execute(['settings' => ['MAILER_DSN=smtp://u:p@smtp.example.com:587']]));
        self::assertStringContainsString('is a secret', $tester->getDisplay());

        $tester->setInputs(['smtp://token:token@smtp.postmarkapp.com:587']);
        self::assertSame(Command::SUCCESS, $tester->execute(['settings' => ['mailer-dsn']], ['interactive' => true]), $tester->getDisplay());
        self::assertSame('smtp://token:token@smtp.postmarkapp.com:587', new ServerFolder($this->runner, $this->root)->env()->get('QUIRE_SERVER_MAILER_DSN'));
        self::assertStringNotContainsString('token:token', $tester->getDisplay());
    }

    public function testWhatAnOwnerDoesntSetIsRefusedAndBadValuesTooWithNothingChanged(): void
    {
        foreach ([
            'IMAGE=ghcr.io/evil/server' => 'update',
            'APP_SECRET=x' => 'installer',
            'BACKUP_PASSWORD=x' => 'backups:setup',
            'NOTIFY_EMAIL=not an email' => 'an email address',
            'QUIRE_VERSION=latest' => 'e.g. ^0.2',
            'UPDATE_HOUR=25' => 'an hour from 0 to 23',
            'GIT_POLL_SECONDS=5' => '60 at least',
            "NAME=two\nlines" => 'control characters',
            'NOPE=1' => 'no setting',
        ] as $setting => $said) {
            $tester = $this->tester($this->setter());
            self::assertSame(Command::FAILURE, $tester->execute(['settings' => [$setting]]), $setting);
            self::assertStringContainsString($said, (string) preg_replace('/\s+/', ' ', $tester->getDisplay()), $setting);
        }

        self::assertSame(self::BEFORE, file_get_contents($this->root.'/server/server.env'));
        self::assertFalse(\in_array('docker compose up', $this->runner->keys(), true));
    }

    public function testSettingsTheServerDoesntStartWithAreUndone(): void
    {
        $this->upFails = true;
        $tester = $this->tester($this->setter());

        self::assertSame(Command::FAILURE, $tester->execute(['settings' => ['NOTIFY_EMAIL=ops@example.com']]));
        self::assertStringContainsString('the ones before are back', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame('darko@example.com', new ServerFolder($this->runner, $this->root)->env()->get('QUIRE_SERVER_NOTIFY_EMAIL'));
    }

    public function testADsnShowsWithoutItsPasswordOrToken(): void
    {
        self::assertSame('smtp://(a token)@smtp.postmarkapp.com:587', ServerSettings::dsnShown('smtp://abc-123:abc-123@smtp.postmarkapp.com:587'));
        self::assertSame('smtps://mail@smtp.example.com', ServerSettings::dsnShown('smtps://mail@smtp.example.com'));
        self::assertNull(ServerSettings::problem('QUIRE_SERVER_MAILER_DSN', 'null://null'));
        self::assertStringContainsString('smtp://', (string) ServerSettings::problem('QUIRE_SERVER_MAILER_DSN', 'postmark+api://token@default'));
    }

    private function setter(): SettingsSetCommand
    {
        return new SettingsSetCommand(
            new ServerFolder($this->runner, $this->root),
            new ServerStack($this->runner, 'tcp://docker-proxy:2375', 'postgres password'),
            new OperationLog($this->connection, $this->schema),
            true,
        );
    }

    private function tester(object $command): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);
        $name = $command instanceof SettingsSetCommand ? 'server:settings:set' : 'server:settings';

        return new CommandTester($application->find($name));
    }
}
