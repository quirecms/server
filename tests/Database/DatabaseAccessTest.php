<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Database;

use Quire\Server\Database\DatabaseAccess;
use Quire\Server\Network\SiteNetworkRange;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\GateFixture;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Quire\Server\Tests\ScriptedRunner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The rules the server's pg_hba.conf includes: who logs in from where.
 */
final class DatabaseAccessTest extends RegistryTestCase
{
    private string $directory;
    private string $subnets = "172.19.0.0/16 \n";
    private string $reload = '';
    private ScriptedRunner $runner;
    private DatabaseAccess $access;
    private SiteRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/quire-access-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->runner = new ScriptedRunner([
            'docker network inspect' => fn (array $command): ProcessResult => ScriptedRunner::ok($command, $this->subnets),
            'docker exec --user' => fn (array $command): ProcessResult => '' === $this->reload ? ScriptedRunner::ok($command) : ScriptedRunner::failed($command, $this->reload),
        ]);
        $this->access = GateFixture::access($this->runner, $this->connection, $this->schema, $this->directory);
        $this->registry = new SiteRegistry($this->connection, $this->schema);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testTheServersRolesLogInOnlyFromItsNetwork(): void
    {
        self::assertTrue($this->access->server());

        self::assertSame([
            'host quire_server_test postgres 172.19.0.0/16 scram-sha-256',
            'host all postgres 172.19.0.0/16 scram-sha-256',
        ], $this->rules(DatabaseAccess::SERVER), 'The registry\'s role to the registry; the administrative one to every database.');
        self::assertSame(['docker network inspect', 'docker exec --user'], $this->runner->keys(), 'PostgreSQL reads them again.');
        self::assertSame(['docker', 'exec', '--user', 'postgres', 'quire-server-database-1', 'pg_ctl', 'reload', '--silent'], $this->runner->runs[1]['command'], 'pg_ctl\'s reload, inside: a docker kill (any signal) counts as stopping it by hand, and then it stays down after the machine restarts.');
        self::assertSame('644', decoct(fileperms($this->directory.'/'.DatabaseAccess::SERVER) & 0o777), 'PostgreSQL\'s own user reads it.');

        self::assertFalse($this->access->server(), 'Nothing changed, nothing reloaded.');
        self::assertSame(['docker network inspect', 'docker exec --user', 'docker network inspect'], $this->runner->keys());
    }

    public function testTheServersNetworkMadeAgainIsWhereItsRolesComeFromNow(): void
    {
        $this->access->server();
        $this->subnets = "172.21.0.0/16 fd00:1::/64 \n";

        self::assertTrue($this->access->server());
        self::assertSame([
            'host quire_server_test postgres 172.21.0.0/16 scram-sha-256',
            'host all postgres 172.21.0.0/16 scram-sha-256',
            'host quire_server_test postgres fd00:1::/64 scram-sha-256',
            'host all postgres fd00:1::/64 scram-sha-256',
        ], $this->rules(DatabaseAccess::SERVER), 'IPv6 too, where Docker gives the network some: a login over it isn\'t refused.');
    }

    public function testWhatDockerSaysIsAnAddressOrNothingIsWritten(): void
    {
        $this->subnets = "172.19.0.0/16 all\n";

        $this->expectExceptionMessageIsOrContains('Docker gave "all" as a subnet of quire-data.');

        try {
            $this->access->server();
        } finally {
            self::assertFileDoesNotExist($this->directory.'/'.DatabaseAccess::SERVER);
        }
    }

    public function testEachSitesRoleLogsInToItsOwnDatabaseOnlyFromItsLink(): void
    {
        $this->site('jane', Site::SHARED_DATABASE);
        $this->site('ada-lovelace', Site::SHARED_DATABASE);
        // Adopted: its own PostgreSQL
        $this->site('demo', Site::OWN_DATABASE);
        $networks = new SiteNetworks($this->connection, $this->schema);
        $range = new SiteNetworkRange(SiteNetworkRange::DEFAULT);

        foreach (['jane', 'ada-lovelace', 'demo'] as $name) {
            $networks->allocate(new SiteName($name), $range, []);
        }

        self::assertTrue($this->access->sites());
        self::assertSame([
            'host site_ada_lovelace site_ada_lovelace 10.72.0.64/27 scram-sha-256',
            'host site_jane site_jane 10.72.0.0/27 scram-sha-256',
        ], $this->rules(DatabaseAccess::SITES));
        self::assertNotContains('docker network inspect', $this->runner->keys(), 'Every site has its link: the server\'s network isn\'t needed.');
    }

    public function testASiteNotOnALinkYetLogsInFromTheServersNetworkAsBefore(): void
    {
        // A site from before D-34, not moved to a link yet: never cut off
        $this->site('jane', Site::SHARED_DATABASE);

        $this->access->sites();

        self::assertSame(['host site_jane site_jane 172.19.0.0/16 scram-sha-256'], $this->rules(DatabaseAccess::SITES));
    }

    public function testASiteBeingRemovedOrRemovedLogsInNoMore(): void
    {
        $this->site('jane', Site::SHARED_DATABASE);
        $this->site('kim', Site::SHARED_DATABASE);
        $this->access->sites();
        $this->registry->setStatus(new SiteName('jane'), Site::REMOVING);
        $this->registry->setStatus(new SiteName('kim'), Site::REMOVED);

        self::assertTrue($this->access->sites());
        self::assertSame([], $this->rules(DatabaseAccess::SITES), 'pg_hba.conf\'s last line refuses them.');
    }

    public function testPostgresqlNotRunningReadsTheRulesWhenItStarts(): void
    {
        $this->reload = 'Error response from daemon: container 5b1f… is not running';

        self::assertTrue($this->access->server());

        // The container runs, PostgreSQL in it hasn't started yet
        $this->reload = "pg_ctl: PID file \"/var/lib/postgresql/18/docker/postmaster.pid\" does not exist\nIs server running?";
        $this->subnets = "172.22.0.0/16\n";
        self::assertTrue($this->access->server());

        $this->reload = 'Error response from daemon: No such container: quire-server-database-1';
        $this->subnets = "172.20.0.0/16\n";
        self::assertTrue($this->access->server());

        $this->reload = 'Cannot connect to the Docker daemon';
        $this->subnets = "172.21.0.0/16\n";
        $this->expectExceptionMessageIsOrContains('Having PostgreSQL read its logins\' rules again');
        $this->access->server();
    }

    public function testTheServersPgHbaRefusesWhatTheAgentsFilesDontAllow(): void
    {
        $rules = (string) file_get_contents(\dirname(__DIR__, 2).'/database/pg_hba.conf');
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $rules)), static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#')));

        self::assertSame('include_if_exists /etc/postgresql/access/'.DatabaseAccess::SERVER, $lines[3]);
        self::assertSame('include_if_exists /etc/postgresql/access/'.DatabaseAccess::SITES, $lines[4]);
        self::assertSame(['host', 'all', 'all', 'all', 'reject'], preg_split('/\s+/', array_last($lines)), 'Last: nothing else, from anywhere.');

        foreach (\array_slice($lines, 0, 3) as $line) {
            self::assertMatchesRegularExpression('#^(local\s+all\s+all|host\s+all\s+all\s+(127\.0\.0\.1/32|::1/128))\s+scram-sha-256$#', $line, 'Before them, only PostgreSQL\'s own container.');
        }

        $compose = (string) file_get_contents(\dirname(__DIR__, 2).'/compose.yaml');
        self::assertStringContainsString('hba_file=/etc/postgresql/pg_hba.conf', $compose);
        self::assertStringContainsString('- database_access:/etc/postgresql/access:ro', $compose, 'PostgreSQL reads the agent\'s files; only the agent writes them.');
        self::assertStringContainsString('- database_access:/run/quire-database/access'."\n", $compose);
    }

    private function site(string $name, string $mode): void
    {
        $this->registry->add(new SiteName($name), [new Domain($name.'.example')], $mode, $name);
    }

    /**
     * @return list<string> The file's rules, without its comments
     */
    private function rules(string $file): array
    {
        $content = (string) file_get_contents($this->directory.'/'.$file);
        self::assertStringStartsWith('# Written by the Quire Server agent', $content);

        return array_values(array_filter(explode("\n", $content), static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#')));
    }
}
