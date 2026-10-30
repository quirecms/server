<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Process;

use PHPUnit\Framework\TestCase;
use Quire\Server\Process\ContainerRunner;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A program in a container of its own, with Docker scripted: what the container gets (no network of
 * the server's, no privileges, only a copy of the files) and what comes back from it.
 */
final class ContainerRunnerTest extends TestCase
{
    private string $tmp;
    private string $directory;
    private ScriptedRunner $runner;

    /** @var (\Closure(string): void)|null What the program does in its folder (the copy) */
    private ?\Closure $program = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/quire-container-'.bin2hex(random_bytes(6));
        $this->directory = $this->tmp.'/agent/composer';
        new Filesystem()->dumpFile($this->directory.'/composer.json', '{"require": {}}');
        new Filesystem()->dumpFile($this->directory.'/composer.lock', 'old lock');
        $this->runner = new ScriptedRunner([
            'docker network inspect' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'docker rm --force' => static fn (array $command): ProcessResult => ScriptedRunner::ok($command),
            'docker run --rm' => function (array $command): ProcessResult {
                if ($this->program instanceof \Closure) {
                    ($this->program)(explode(':', $this->after($command, '--volume'))[0]);
                }

                return ScriptedRunner::ok($command, 'resolved');
            },
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmp);
    }

    public function testTheProgramRunsWithNoneOfTheServersNetworksOrPowers(): void
    {
        $result = $this->subject()->run(['composer', 'update', '--working-dir='.$this->directory], ['COMPOSER_HOME' => '/tmp/composer', 'COMPOSER_AUTH' => false], $this->directory);

        self::assertSame(['composer', 'update', '--working-dir='.$this->directory], $result->command, 'Reported as the program it ran.');
        self::assertSame('resolved', $result->output);

        $run = $this->runOf('docker run --rm');
        $flags = implode(' ', $run['command']);
        self::assertStringContainsString('--network quire-sandbox', $flags);
        self::assertSame(1, substr_count($flags, '--network'), 'One network: its own.');
        self::assertStringContainsString('--cap-drop ALL --security-opt no-new-privileges:true --read-only', $flags);
        self::assertStringContainsString('--user '.$this->uid().':'.$this->uid(), $flags);
        self::assertSame(1, substr_count($flags, '--volume'), 'Only the copy of the folder.');
        self::assertStringStartsWith($this->tmp.'/work/quire-sandbox-', $this->after($run['command'], '--volume'));
        self::assertStringEndsWith('agent-image composer update --working-dir=/work', $flags, 'The folder as the program sees it.');
        self::assertContains('COMPOSER_HOME=/tmp/composer', $run['command']);
        self::assertStringNotContainsString('COMPOSER_AUTH', $flags, 'Nothing to remove: a container inherits nothing.');
        self::assertSame(['DOCKER_HOST' => 'tcp://docker-proxy:2375'], $run['env']);

        self::assertDirectoryDoesNotExist($this->tmp.'/work/'.$this->after($run['command'], '--name'), 'The copy is gone.');
        self::assertSame(['docker', 'rm', '--force', $this->after($run['command'], '--name')], $this->runOf('docker rm --force')['command'], 'Stopped for sure.');
    }

    public function testWhatTheProgramWritesComesBackButLinksDont(): void
    {
        $this->program = static function (string $copy): void {
            self::assertSame('{"require": {}}', file_get_contents($copy.'/composer.json'), 'It works on a copy.');
            file_put_contents($copy.'/composer.lock', 'new lock');
            symlink('/etc/passwd', $copy.'/passwd');
            unlink($copy.'/composer.json');
            symlink('/etc/hostname', $copy.'/composer.json');
            mkdir($copy.'/vendor');
        };

        $this->subject()->run(['composer', 'update'], [], $this->directory);

        self::assertSame('new lock', file_get_contents($this->directory.'/composer.lock'));
        self::assertSame('{"require": {}}', file_get_contents($this->directory.'/composer.json'), 'A link from the container isn\'t followed.');
        self::assertFileDoesNotExist($this->directory.'/passwd');
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
        self::assertSame(['docker network inspect', 'docker run --rm', 'docker rm --force'], \array_slice($this->runner->keys(), 0, 3), 'Removed before its files are read.');
    }

    public function testItRunsOnlyInAFolderOfFiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject()->run(['composer', 'update']);
    }

    /**
     * @return array{command: list<string>, env: array<string, string|false>, directory: ?string, inputFile: ?string}
     */
    private function runOf(string $key): array
    {
        foreach ($this->runner->runs as $run) {
            if (ScriptedRunner::key($run['command']) === $key) {
                return $run;
            }
        }

        self::fail('No '.$key.'.');
    }

    /**
     * @param list<string> $command
     */
    private function after(array $command, string $option): string
    {
        $at = array_search($option, $command, true);

        return false === $at ? '' : $command[$at + 1] ?? '';
    }

    private function uid(): int
    {
        return (int) getmyuid();
    }

    private function subject(): ContainerRunner
    {
        return new ContainerRunner($this->runner, 'tcp://docker-proxy:2375', 'agent-image', $this->tmp.'/work', $this->uid());
    }
}
