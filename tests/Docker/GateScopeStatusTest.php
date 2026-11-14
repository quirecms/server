<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\TestCase;
use Quire\Server\Docker\GateScopeStatus;
use Symfony\Component\Process\Process;

/**
 * The agent asking the gate about a site's scope, on a unix socket that answers as YorvoGate 0.3 does.
 */
final class GateScopeStatusTest extends TestCase
{
    private string $socket;

    private ?Process $gate = null;

    protected function setUp(): void
    {
        $this->socket = sys_get_temp_dir().'/scope-status-'.bin2hex(random_bytes(4)).'.sock';
    }

    protected function tearDown(): void
    {
        $this->gate?->stop(0);
        @unlink($this->socket);
        @unlink($this->socket.'.request');
    }

    public function testALoadedScopeOfTheFileWritten(): void
    {
        $sha = str_repeat('a', 64);
        $this->answer(200, ['version' => 1, 'listener' => 'sites', 'scope' => 'jane', 'file_sha256' => $sha, 'state' => 'loaded', 'matched' => true]);

        self::assertSame(['state' => 'loaded', 'reason' => null], $this->client()->read('jane', $sha));
        self::assertSame('GET /_yorvogate/v1/scopes/jane?listener=sites&sha256='.$sha.' HTTP/1.0', $this->requestLine());
    }

    public function testARefusedScopeSaysWhy(): void
    {
        $this->answer(200, ['scope' => 'jane', 'state' => 'refused', 'reason' => 'a builder under another prefix', 'matched' => true]);

        self::assertSame(['state' => 'refused', 'reason' => 'a builder under another prefix'], $this->client()->read('jane', str_repeat('b', 64)));
    }

    public function testAWaitThatTimesOutIsAFailureNeverTheStateWaitedFor(): void
    {
        $this->answer(408, ['scope' => 'jane', 'state' => 'loaded', 'file_sha256' => str_repeat('c', 64), 'matched' => false]);
        $this->expectExceptionMessageIsOrContains('The Docker gate didn\'t read the new scope of "jane" in time (it has another file)');

        $this->client()->read('jane', str_repeat('d', 64));
    }

    public function testGone(): void
    {
        $this->answer(200, ['scope' => 'jane', 'state' => 'unknown', 'matched' => true]);

        $this->client()->gone('jane');
        self::assertSame('GET /_yorvogate/v1/scopes/jane?listener=sites&absent HTTP/1.0', $this->requestLine());
    }

    public function testStillThereIsAFailure(): void
    {
        $this->answer(408, ['scope' => 'jane', 'state' => 'loaded', 'matched' => false]);
        $this->expectExceptionMessageIsOrContains('The Docker gate still had a scope for "jane" (HTTP 408)');

        $this->client()->gone('jane');
    }

    public function testAnAnswerAboutAnotherScopeIsRefused(): void
    {
        $this->expectExceptionMessageIsOrContains('answered about another scope than "jane"');

        GateScopeStatus::parse("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n".'{"scope":"jane-blog","state":"loaded","matched":true}', 'jane');
    }

    public function testOnlyAProjectsNameGoesInThePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client()->read('../trusted', str_repeat('e', 64));
    }

    public function testNoGateIsSaid(): void
    {
        $this->expectExceptionMessageIsOrContains('The Docker gate can\'t be reached on '.$this->socket);

        $this->client()->read('jane', str_repeat('f', 64));
    }

    private function client(): GateScopeStatus
    {
        return new GateScopeStatus('unix://'.$this->socket);
    }

    /**
     * A one-request server on the socket: it keeps the request's first line, then answers.
     *
     * @param array<string, mixed> $body
     */
    private function answer(int $status, array $body): void
    {
        $script = <<<'PHP'
            $server = stream_socket_server('unix://'.$argv[1], $code, $message) ?: exit(1);
            $client = stream_socket_accept($server, 30) ?: exit(2);
            file_put_contents($argv[1].'.request', strtok((string) fgets($client), "\r\n"));
            while (!in_array(fgets($client), ["\r\n", false], true));
            fwrite($client, "HTTP/1.1 {$argv[2]} X\r\nContent-Type: application/json\r\n\r\n".$argv[3]);
            fclose($client);
            PHP;
        $this->gate = new Process([\PHP_BINARY, '-r', $script, $this->socket, (string) $status, json_encode($body, \JSON_THROW_ON_ERROR)]);
        $this->gate->start();

        for ($i = 0; $i < 100 && !file_exists($this->socket); ++$i) {
            usleep(20_000);
        }
    }

    private function requestLine(): string
    {
        $this->gate?->wait();

        return (string) file_get_contents($this->socket.'.request');
    }
}
