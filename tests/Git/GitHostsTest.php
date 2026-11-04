<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Git;

use PHPUnit\Framework\TestCase;
use Quire\Server\Git\GitHosts;

final class GitHostsTest extends TestCase
{
    public function testAPublicNameGivesItsAddressIpv4First(): void
    {
        self::assertSame('140.82.121.4', $this->hosts(['github.com' => ['2606:50c0:8000::154', '140.82.121.4']])->address('GitHub.com'));
    }

    public function testANameOnAPrivateOrLocalAddressIsRefused(): void
    {
        foreach (['10.0.0.5', '192.168.1.1', '127.0.0.1', '169.254.169.254', '::1', 'fd00::1', '172.18.0.3'] as $address) {
            try {
                $this->hosts(['git.example.com' => ['140.82.121.4', $address]])->address('git.example.com');
                self::fail($address.' was taken.');
            } catch (\DomainException $e) {
                self::assertStringContainsString($address, $e->getMessage(), 'Any of its addresses: one is enough to be sent there.');
                self::assertStringContainsString('QUIRE_SERVER_GIT_PRIVATE_HOSTS', $e->getMessage(), 'Says how to allow one of the owner\'s.');
            }
        }
    }

    public function testAnOwnersGitServerIsAllowedByName(): void
    {
        $hosts = $this->hosts(['git.internal.example' => ['10.135.0.7'], 'other.internal.example' => ['10.135.0.8']], ' Git.Internal.Example , ');

        self::assertSame('10.135.0.7', $hosts->address('git.internal.example'));
        $this->expectException(\DomainException::class);
        $hosts->address('other.internal.example');
    }

    public function testANameWithoutAddressIsRefused(): void
    {
        $this->expectExceptionMessageIsOrContains('has no address');
        $this->hosts([])->address('gone.example.com');
    }

    /**
     * @param array<string, list<string>> $dns
     */
    private function hosts(array $dns, string $private = ''): GitHosts
    {
        return new GitHosts($private, static fn (string $host): array => $dns[$host] ?? []);
    }
}
