<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Registry;

use Quire\Server\Operation\Operation;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Site\SiteName;

final class OperationLogTest extends RegistryTestCase
{
    public function testAnOperationIsRecordedAsItGoes(): void
    {
        $log = new OperationLog($this->connection, $this->schema);
        $operation = $log->start('site.add', new SiteName('jane'), ['domains' => ['jane.example']], 'cli', 'darko');

        $operation->output("step one\nstep ");
        $operation->output("two\n");
        $operation->output('unfinished');
        $operation->succeed();

        self::assertSame(['step one', 'step two', 'unfinished'], $log->lines($operation->id));
        $latest = $log->latest(new SiteName('jane'));
        self::assertSame(['site.add', 'jane', 'cli', 'darko', 'succeeded', null], [$latest[0]['action'], $latest[0]['site'], $latest[0]['channel'], $latest[0]['requested_by'], $latest[0]['status'], $latest[0]['error']]);
    }

    public function testSecretsAndTerminalCodesNeverReachTheLog(): void
    {
        $log = new OperationLog($this->connection, $this->schema);
        $operation = $log->start('site.add', null, [], 'cli', 'root');
        $operation->secret('hunter2-secret-value');
        $operation->secret('short');

        $operation->line('The password is: hunter2-secret-value');
        $operation->line("hunter2-\e[1msecret-value split by an escape code");
        $operation->line("\e[31mred\e[0m and a bell\x07 and \e]0;title\x07");
        $operation->line(str_repeat('x', 5000));
        $operation->fail('Failed with hunter2-secret-value in the message');

        $lines = $log->lines($operation->id);
        self::assertSame('The password is: [secret]', $lines[0]);
        self::assertSame('[secret] split by an escape code', $lines[1]);
        self::assertStringNotContainsString("\e", $lines[2]);
        self::assertStringNotContainsString("\x07", $lines[2]);
        self::assertSame(2001, mb_strlen($lines[3]), 'Long lines are cut.');
        self::assertStringContainsString('short', implode('', $lines).'short', 'Values under 6 characters aren\'t secrets (they\'d blank common words).');
        self::assertSame('Failed with [secret] in the message', $log->latest()[0]['error']);
    }

    public function testARunawayLogIsCapped(): void
    {
        $log = new OperationLog($this->connection, $this->schema);
        $operation = $log->start('site.add', null, [], 'cli', 'root');

        for ($i = 0; $i < Operation::MAX_LINES + 50; ++$i) {
            $operation->line('line '.$i);
        }

        $lines = $log->lines($operation->id);
        self::assertCount(Operation::MAX_LINES + 1, $lines);
        self::assertStringContainsString('the rest isn\'t kept', $lines[Operation::MAX_LINES]);
    }

    public function testInterruptedOperationsAreMarkedFailed(): void
    {
        $log = new OperationLog($this->connection, $this->schema);
        $log->start('site.add', new SiteName('jane'), [], 'cli', 'root');

        self::assertSame(1, $log->abandonRunning());
        self::assertSame(['failed', OperationLog::INTERRUPTED], [$log->latest()[0]['status'], $log->latest()[0]['error']]);
    }

    public function testASecretIsScrubbedAsProgramsPrintItEncoded(): void
    {
        $log = new OperationLog($this->connection, $this->schema);
        $operation = $log->start('site.update', null, [], 'cli', 'darko');
        $operation->secret('p@ss/w0rd+"x');
        $operation->secret('sixsix');
        $operation->secret('five5');

        $operation->line('URL-encoded: '.rawurlencode('p@ss/w0rd+"x').' and form-encoded: '.urlencode('p@ss/w0rd+"x'));
        $operation->line('In JSON: '.json_encode(['password' => 'p@ss/w0rd+"x']));
        $operation->line('An address: smtp://mailer:an-unregistered-pass@mail.example:587 and postgresql://u:pw@db/x');
        $operation->line('Six: sixsix; five: five5');
        $operation->succeed();

        $lines = $log->lines($operation->id);
        self::assertStringNotContainsString('w0rd', implode("\n", $lines));
        self::assertSame('An address: smtp://mailer:[secret]@mail.example:587 and postgresql://u:[secret]@db/x', $lines[2], 'Whoever\'s password it is.');
        self::assertSame('Six: [secret]; five: five5', $lines[3], '6 characters are a secret; 5 aren\'t (they\'d blank common words).');
    }

    public function testAnOperationOnASiteIsScrubbedOfItsSettingsSecretsAndTheAgents(): void
    {
        $root = sys_get_temp_dir().'/quire-oplog-'.bin2hex(random_bytes(6));
        new \Symfony\Component\Filesystem\Filesystem()->dumpFile($root.'/sites/jane/prod.env', "APP_SECRET='the-sites-app-secret'\nQUIRE_DATABASE_PASSWORD='the-sites-db-password'\nMAILER_DSN='smtp://jane:the-mail-password@mail.example'\nSERVER_NAME='jane.example'\n");
        putenv('QUIRE_SERVER_TEST_TOKEN=the-agents-own-token');

        try {
            $log = new OperationLog($this->connection, $this->schema, new \Quire\Server\Site\ServerPaths($root));
            $operation = $log->start('site.rebuild', new SiteName('jane'), [], 'agent', 'automatic');
            $operation->line('APP_SECRET=the-sites-app-secret QUIRE_DATABASE_PASSWORD=the-sites-db-password the-mail-password the-agents-own-token jane.example');
            $operation->succeed();

            self::assertSame('APP_SECRET=[secret] QUIRE_DATABASE_PASSWORD=[secret] [secret] [secret] jane.example', $log->lines($operation->id)[0], 'Without the caller registering any (MAINT-09): not its domain.');
        } finally {
            putenv('QUIRE_SERVER_TEST_TOKEN');
            new \Symfony\Component\Filesystem\Filesystem()->remove($root);
        }
    }

    public function testASitesOperationsAreOnlyItsOwn(): void
    {
        $log = new OperationLog($this->connection, $this->schema);
        $log->start('site.add', new SiteName('jane'), [], 'cli', 'darko')->succeed();
        $log->start('site.add', new SiteName('kim'), [], 'cli', 'darko')->succeed();
        $log->start('backups.nightly', null, [], 'agent', 'automatic')->succeed();

        self::assertSame(['jane'], array_column($log->latest(new SiteName('jane')), 'site'), 'Not another site\'s, nor the server\'s (STATIC-07).');
        self::assertCount(3, $log->latest());
    }
}
