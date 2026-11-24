<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\TestCase;

/**
 * The AppArmor profile sites' builders run under is YorvoGate's own, of the version the server runs:
 * gate/host-setup.sh writes its release file byte for byte.
 */
final class BuilderProfileTest extends TestCase
{
    /** The YorvoGate the server runs, and its release file's SHA-256 (its SHA256SUMS) */
    private const string VERSION = '0.4.0';

    private const string PROFILE_SHA256 = 'ce7283116aba36729ac14c0c1e02d8c1143daca30243abd38cafd7a1a08a79e0';

    public function testHostSetupWritesTheReleasesProfile(): void
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2).'/gate/host-setup.sh');

        self::assertSame(1, preg_match("/cat >\"\\\$temporary\" <<'EOF'\n(.*?\n)EOF\n/s", $script, $match));
        self::assertSame(self::PROFILE_SHA256, hash('sha256', $match[1]), 'Not YorvoGate '.self::VERSION.'\'s yorvogate-buildkit-rootless: take it from that release (its SHA256SUMS).');
    }

    public function testItIsTheVersionTheServerRuns(): void
    {
        $compose = (string) file_get_contents(\dirname(__DIR__, 2).'/compose.yaml');

        self::assertStringContainsString('ghcr.io/yorvolabs/yorvogate:'.self::VERSION.'@sha256:', $compose, 'A new YorvoGate: take its profile and checksum here too.');
    }
}
