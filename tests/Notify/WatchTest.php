<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Notify;

use Quire\Server\Backup\BackupSettings;
use Quire\Server\Backup\OffsiteBackups;
use Quire\Server\Notify\Certificates;
use Quire\Server\Notify\Problem;
use Quire\Server\Notify\Watch;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WatchTest extends RegistryTestCase
{
    private OperationLog $operations;
    private SiteRegistry $registry;
    private string $certificates;
    private \DateTimeImmutable $now;

    /** @var array{free: float, total: float} */
    private array $disk = ['free' => 50e9, 'total' => 100e9];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->operations = new OperationLog($this->connection, $this->schema);
        $this->registry = new SiteRegistry($this->connection, $this->schema);
        $this->certificates = sys_get_temp_dir().'/quire-certificates-'.bin2hex(random_bytes(6));
        $this->now = new \DateTimeImmutable();

        foreach (['jane', 'kim'] as $name) {
            $this->registry->add(new SiteName($name), [new Domain($name.'.example')], Site::SHARED_DATABASE, $name);
            $this->registry->setStatus(new SiteName($name), Site::RUNNING);
            $this->certificate($name.'.example', 90);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->certificates);
        parent::tearDown();
    }

    public function testNothingIsWrongOnAWellServer(): void
    {
        $this->operations->start('site.update', new SiteName('jane'), [], 'agent', 'automatic')->succeed();

        self::assertSame([], $this->problems());
    }

    public function testTheAgentsOwnFailureIsAProblemUntilOneOfItsKindSucceeds(): void
    {
        $failed = $this->operations->start('site.update', new SiteName('jane'), [], 'agent', 'automatic');
        $failed->fail('Composer couldn\'t resolve');

        $problems = $this->problems();
        self::assertSame(['operation:site.update:jane'], array_keys($problems));
        self::assertSame('The automatic update of jane failed.', $problems['operation:site.update:jane']->summary);
        self::assertStringContainsString('"quire-server operations '.$failed->id.'"', $problems['operation:site.update:jane']->advice);
        self::assertStringNotContainsString('Composer', $problems['operation:site.update:jane']->advice, 'No tool output in a notification: it says where to read it.');
        self::assertSame([$failed->id, 'jane'], [$problems['operation:site.update:jane']->operation, $problems['operation:site.update:jane']->site]);

        // The owner tries again, by hand: that ends it
        $this->operations->start('site.update', new SiteName('jane'), [], 'cli', 'darko')->succeed();
        self::assertSame([], $this->problems());
    }

    public function testWhatTheOwnerRanAndSawFailIsntToldAgain(): void
    {
        $this->operations->start('site.update', new SiteName('jane'), [], 'cli', 'darko')->fail('no');
        $this->operations->start('site.add', new SiteName('kim'), [], 'agent', 'agent')->fail('Not watched: not the agent\'s own kind of work.');

        self::assertSame([], $this->problems());
    }

    public function testEachKindAndSiteIsItsOwnProblem(): void
    {
        $this->operations->start('site.deploy', new SiteName('jane'), [], 'agent', 'polling')->fail('x');
        $this->operations->start('site.update', new SiteName('jane'), [], 'agent', 'automatic')->fail('x');
        $this->operations->start('site.update', new SiteName('kim'), [], 'agent', 'automatic')->fail('x');
        $this->operations->start(OffsiteBackups::NIGHTLY, null, [], 'agent', 'automatic')->fail('x');

        self::assertSame(['operation:backups.nightly', 'operation:site.deploy:jane', 'operation:site.update:jane', 'operation:site.update:kim'], $this->sortedKeys());
    }

    public function testOldFailuresAndRemovedSitesArentNews(): void
    {
        $old = $this->operations->start('site.update', new SiteName('jane'), [], 'agent', 'automatic');
        $old->fail('x');
        $this->connection->executeStatement(\sprintf("UPDATE %s.operation SET started_at = now() - interval '15 days' WHERE id = ?", $this->quoted()), [$old->id]);
        $this->operations->start('site.update', new SiteName('kim'), [], 'agent', 'automatic')->fail('x');
        $this->registry->setStatus(new SiteName('kim'), Site::REMOVED);

        self::assertSame([], $this->problems());
    }

    public function testNightlyBackupsThatStoppedRunning(): void
    {
        $this->operations->start(OffsiteBackups::NIGHTLY, null, [], 'agent', 'automatic')->succeed();
        self::assertSame([], $this->problems(backups: true));

        $this->now = new \DateTimeImmutable('+37 hours');
        $problems = $this->problems(backups: true);
        self::assertSame(['backups.late'], array_keys($problems));
        self::assertStringStartsWith('Backups off the server haven\'t run since ', $problems['backups.late']->summary);

        self::assertSame([], $this->problems(backups: false), 'None set up: none late.');
    }

    public function testACertificateThatDoesntComeOrIsntRenewed(): void
    {
        $this->registry->add(new SiteName('lee'), [new Domain('lee.example')], Site::SHARED_DATABASE, 'lee');
        $this->registry->setStatus(new SiteName('lee'), Site::RUNNING);
        self::assertSame([], $this->problems(), 'Just added: it comes within minutes.');

        $this->now = new \DateTimeImmutable('+61 minutes');
        self::assertSame(['certificate:lee.example'], array_keys($this->problems()));
        self::assertStringContainsString('has no certificate', $this->problems()['certificate:lee.example']->summary);

        // 90 days, renewed by Caddy with 30 left: 14 days left means it has been failing for two weeks
        $this->now = new \DateTimeImmutable('+77 days');
        $problems = $this->problems();
        self::assertSame(['certificate:jane.example', 'certificate:kim.example', 'certificate:lee.example'], $this->sortedKeys());
        self::assertStringContainsString('its certificate expires', $problems['certificate:jane.example']->summary);
        self::assertStringContainsString('isn\'t being renewed', $problems['certificate:jane.example']->summary);
        self::assertSame('jane', $problems['certificate:jane.example']->site);

        $this->now = new \DateTimeImmutable('+91 days');
        self::assertStringContainsString('its certificate expired', $this->problems()['certificate:jane.example']->summary);
    }

    public function testAShortLivedCertificateIsLateSooner(): void
    {
        // Let's Encrypt's six-day certificates: renewed with two days left; under a day left is late
        $this->certificate('jane.example', 6);
        $this->certificate('kim.example', 90);
        $this->now = new \DateTimeImmutable('+4 days');
        self::assertSame([], $this->problems(), 'Two days left: not late.');

        $this->now = new \DateTimeImmutable('+5 days 2 hours');
        self::assertSame(['certificate:jane.example'], $this->sortedKeys());
    }

    public function testADiskFillingUp(): void
    {
        $this->disk = ['free' => 8e9, 'total' => 100e9];
        self::assertSame('The disk is almost full: 7.5 GB free of 93.1 GB.', $this->problems()['disk']->summary ?? null, 'Under a tenth.');

        $this->disk = ['free' => 4e9, 'total' => 20e9];
        self::assertArrayHasKey('disk', $this->problems(), 'Under 5 GB.');

        $this->disk = ['free' => 6e9, 'total' => 20e9];
        self::assertSame([], $this->problems());
    }

    /**
     * @return array<string, Problem>
     */
    private function problems(bool $backups = false): array
    {
        $settings = $backups ? new BackupSettings('s3:https://s3.example/bucket', 'key', 'secret', str_repeat('p', 20)) : new BackupSettings('', '', '', '');
        $watch = new Watch($this->connection, $settings, new Certificates($this->certificates), new ServerPaths('/srv/quire'), $this->schema, fn (): \DateTimeImmutable => $this->now, fn (): array => $this->disk);
        $problems = [];

        foreach ($watch->problems() as $problem) {
            $problems[$problem->key] = $problem;
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function sortedKeys(): array
    {
        $keys = array_keys($this->problems());
        sort($keys);

        return $keys;
    }

    /**
     * A certificate for the domain, valid from now for some days, where Caddy keeps Let's Encrypt's.
     */
    private function certificate(string $domain, int $days): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => $domain], $key);
        self::assertNotFalse($request);
        self::assertNotTrue($request);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $certificate = openssl_csr_sign($request, null, $key, $days);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));
        self::assertIsString($pem);
        new Filesystem()->dumpFile($this->certificates.'/acme-v02.api.letsencrypt.org-directory/'.$domain.'/'.$domain.'.crt', $pem);
    }

    private function quoted(): string
    {
        return $this->connection->quoteSingleIdentifier($this->schema);
    }
}
