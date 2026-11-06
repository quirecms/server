<?php

declare(strict_types=1);

namespace Quire\Server\Database;

use Doctrine\DBAL\Connection;
use Quire\Server\Network\SiteLinks;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;
use Quire\Server\Registry\SiteNetworks;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;

/**
 * Who may log in to the server's PostgreSQL, and from where (D-34): its pg_hba.conf (database/pg_hba.conf,
 * the server's own) includes two files the agent writes, in a volume only the agent can write to:
 *
 *   - server.conf: the server's roles (the registry's, and the administrative one), only from the
 *     server's network (quire-data). Its addresses are Docker's, so the agent writes it at each start
 *     before it logs in: it never locks itself out.
 *   - sites.conf: each site's role, to its own database, only from its link. A stolen password is of
 *     no use from another site's containers.
 *
 * Anything else is refused, but inside PostgreSQL's own container (dumps and restores run there). A
 * change takes effect with a reload, never a restart; a file PostgreSQL can't read is put back as it
 * was before it's reloaded, as PostgreSQL wouldn't start with it.
 *
 * The reload is pg_ctl's, run inside the container: never "docker kill --signal HUP", which Docker
 * counts as stopping the container by hand (any signal does), so that "restart: unless-stopped" no
 * longer brings PostgreSQL back after the machine restarts, and every site stays down.
 */
final readonly class DatabaseAccess
{
    public const string SERVER = 'server.conf';
    public const string SITES = 'sites.conf';

    /** The network the agent and the updater reach PostgreSQL on */
    public const string NETWORK = 'quire-data';

    /** A role or database name as pg_hba.conf takes it without quotes */
    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]{0,62}\z/';

    private const string HEADER = "# Written by the Quire Server agent (D-34), and written again when it changes: don't edit it.\n";

    public function __construct(
        private SiteRegistry $registry,
        private SiteNetworks $networks,
        private Connection $connection,
        private Connection $admin,
        private SiteFiles $files,
        private ProcessRunner $runner,
        private string $dockerHost,
        private string $directory,
    ) {
    }

    /**
     * The server's roles, from the server's network as Docker has it now. Needs no login.
     *
     * @return bool Whether it changed
     */
    public function server(): bool
    {
        $roles = [$this->identifier($this->connection, 'dbname'), $this->identifier($this->connection, 'user'), $this->identifier($this->admin, 'user')];
        $lines = [];

        foreach ($this->dataSubnets() as $subnet) {
            // The registry's role to the registry only; the administrative one manages every database
            $lines[] = \sprintf('host %s %s %s scram-sha-256', $roles[0], $roles[1], $subnet);
            $lines[] = \sprintf('host all %s %s scram-sha-256', $roles[2], $subnet);
        }

        return $this->write(self::SERVER, "# The server's roles, from its own network (".self::NETWORK.")\n".implode("\n", $lines)."\n", false);
    }

    /**
     * Each site on the server's PostgreSQL: its role to its database, from its link. A site that has
     * no link yet (it ran before D-34, and hasn't been moved to one) logs in from the server's network,
     * as it did: it's never cut off.
     *
     * @return bool Whether it changed
     */
    public function sites(): bool
    {
        $links = $this->networks->all();
        $lines = [];
        $data = null;

        foreach ($this->registry->all() as $site) {
            // An adopted site has its own; a site being removed has none any more
            if (Site::SHARED_DATABASE !== $site->databaseMode || Site::REMOVING === $site->status) {
                continue;
            }

            $identifier = $site->name->databaseIdentifier();

            if (1 !== preg_match(self::IDENTIFIER, $identifier)) {
                throw new \LogicException(\sprintf('"%s" isn\'t a database name pg_hba.conf takes.', $identifier));
            }

            foreach (isset($links[$site->name->value]) ? [(string) $links[$site->name->value]->link] : ($data ??= $this->dataSubnets()) as $from) {
                $lines[] = \sprintf('host %1$s %1$s %2$s scram-sha-256', $identifier, $from);
            }
        }

        return $this->write(self::SITES, "# Each site's role, to its own database, from its link (".SiteLinks::PREFIX."<project>)\n".implode("\n", $lines).([] === $lines ? '' : "\n"), true);
    }

    /**
     * @return list<string> The server's network's subnets (IPv6 too, where Docker gives it some)
     */
    private function dataSubnets(): array
    {
        $result = $this->docker(['network', 'inspect', '--format', '{{range .IPAM.Config}}{{.Subnet}} {{end}}', self::NETWORK])->orFail('Reading the server\'s network ('.self::NETWORK.')');
        $subnets = [];

        foreach (explode(' ', trim($result->output)) as $subnet) {
            if ('' === $subnet) {
                continue;
            }

            [$address, $prefix] = explode('/', $subnet, 2) + [1 => ''];
            $packed = inet_pton($address);
            $bits = false === $packed ? 0 : 8 * \strlen($packed);

            if (0 === $bits || 1 !== preg_match('/^\d{1,3}\z/', $prefix) || (int) $prefix > $bits) {
                throw new \RuntimeException(\sprintf('Docker gave "%s" as a subnet of %s.', $subnet, self::NETWORK));
            }

            $subnets[] = $subnet;
        }

        if ([] === $subnets) {
            throw new \RuntimeException(\sprintf('The server\'s network (%s) has no addresses.', self::NETWORK));
        }

        return $subnets;
    }

    /**
     * Writes one of the files, and has PostgreSQL read it again when it changed.
     *
     * @param bool $check Whether PostgreSQL is asked first if it can read it (it needs a login)
     */
    private function write(string $file, string $content, bool $check): bool
    {
        $path = $this->directory.'/'.$file;
        $content = self::HEADER.$content;
        $lock = fopen($this->directory.'/.lock', 'c');

        if (false === $lock) {
            throw new \RuntimeException(\sprintf('Couldn\'t open "%s/.lock".', $this->directory));
        }

        // One writer at a time (the agent's round, an operation on a site): each writes what the
        // registry has once it holds the lock, so the last one is never older than another.
        flock($lock, \LOCK_EX);

        try {
            $before = is_file($path) ? (string) file_get_contents($path) : null;

            if ($before === $content) {
                return false;
            }

            $this->files->write($path, $content, 0o644);

            if ($check) {
                $this->checked($path, $before);
            }

            $this->reload();

            return true;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * PostgreSQL's own reading of its rules, the new file included (pg_hba_file_rules reads them from
     * disk): an error puts the file back as it was.
     */
    private function checked(string $path, ?string $before): void
    {
        $errors = $this->admin->fetchFirstColumn('SELECT file_name || \':\' || line_number || \': \' || error FROM pg_hba_file_rules WHERE error IS NOT NULL');

        if ([] === $errors) {
            return;
        }

        null === $before ? unlink($path) : $this->files->write($path, $before, 0o644);

        throw new \RuntimeException('PostgreSQL can\'t read its logins\' rules, so they\'re as they were: '.implode('; ', array_map(static fn (mixed $error): string => \is_scalar($error) ? (string) $error : '', $errors)));
    }

    /**
     * PostgreSQL reads its rules again (what pg_reload_conf() does), without a login: these rules may
     * be what lets the agent log in. Not running (the container, or PostgreSQL in it, not started
     * yet): it reads them when it starts.
     */
    private function reload(): void
    {
        $reloaded = $this->docker(['exec', '--user', 'postgres', SiteLinks::DATABASE, 'pg_ctl', 'reload', '--silent']);

        if ($reloaded->successful()) {
            return;
        }

        foreach (['No such container', 'is not running', 'Is server running?'] as $notRunning) {
            if (str_contains($reloaded->errorOutput, $notRunning) || str_contains($reloaded->output, $notRunning)) {
                return;
            }
        }

        $reloaded->orFail('Having PostgreSQL read its logins\' rules again');
    }

    private function identifier(Connection $connection, string $parameter): string
    {
        $value = $connection->getParams()[$parameter] ?? null;

        if (!\is_string($value) || 1 !== preg_match(self::IDENTIFIER, $value)) {
            throw new \LogicException(\sprintf('The server\'s database connection has no %s pg_hba.conf takes.', $parameter));
        }

        return $value;
    }

    /**
     * @param list<string> $arguments
     */
    private function docker(array $arguments): ProcessResult
    {
        return $this->runner->run(['docker', ...$arguments], ['DOCKER_HOST' => $this->dockerHost], timeout: 60);
    }
}
