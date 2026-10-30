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
}
