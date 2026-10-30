<?php

declare(strict_types=1);

namespace Quire\Server\ServerDatabase;

use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;

/**
 * What an upgrade of the server's PostgreSQL does on Docker, from the updater: the containers that use
 * the server's PostgreSQL (stopped while it's copied: nothing written to it is lost), the new major
 * started beside the old one on the same volume (in a folder of its own: /var/lib/postgresql/<major>),
 * the copy and its check, and the majors' folders on the volume.
 *
 * Passwords only go through the environment (Docker passes "--env NAME" on from its own), and the
 * names of databases only as arguments of a fixed script: nothing is ever interpreted by a shell.
 */
final readonly class DatabaseContainers
{
    /** The new major while it's being filled; never on the network under PostgreSQL's alias */
    public const string BESIDE = 'quire-server-database-upgrade';

    /** Where the volume is in the containers this starts: not where PostgreSQL's image looks for old data */
    private const string MOUNT = '/quire-upgrade';

    private const string PROJECT = 'quire-server';
    private const string NETWORK = 'quire-data';

    /**
     * The roles (with their passwords' hashes), then each database (with its owner, rights and
     * settings), dumped by the new major's tools from the old major and restored into the new one;
     * statistics made again. Only the new cluster's own superuser is already there.
     */
    private const string COPY = <<<'SH'
        set -eu
        set -o pipefail
        echo 'Copying the roles'
        pg_dumpall --host=quire-database --username=postgres --globals-only --no-tablespaces \
            | grep -vx 'CREATE ROLE postgres;' \
            | psql --no-psqlrc --quiet --username=postgres --dbname=postgres --set=ON_ERROR_STOP=1
        for database in "$@"; do
            echo "Copying $database"
            pg_dump --host=quire-database --username=postgres --format=custom --create --dbname="$database" \
                | pg_restore --username=postgres --dbname=postgres --create --exit-on-error
        done
        echo 'Making the statistics again'
        vacuumdb --username=postgres --all --analyze-only --quiet
        SH;

    public function __construct(
        private ProcessRunner $runner,
        private string $dockerHost,
        private string $postgresPassword,
        private \Closure $pause = sleep(...),
    ) {
    }

    /**
     * The server's PostgreSQL volume (each major's data in a folder of its own on it).
     */
    public function volume(): string
    {
        $ids = $this->lines($this->docker(['ps', '--all', '--quiet', '--filter', 'label=com.docker.compose.project='.self::PROJECT, '--filter', 'label=com.docker.compose.service=database'])->orFail('Finding the server\'s PostgreSQL'));
        $id = $ids[0] ?? throw new \RuntimeException('The server\'s PostgreSQL container isn\'t there.');
        $volume = trim($this->docker(['inspect', '--format', '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql"}}{{.Type}} {{.Name}}{{end}}{{end}}', $id])->orFail('Finding the server\'s PostgreSQL volume')->output);

        if (1 !== preg_match('/^volume ([A-Za-z0-9][A-Za-z0-9_.-]*)\z/', $volume, $match)) {
            throw new \RuntimeException('The server\'s PostgreSQL keeps its data elsewhere than in a volume at /var/lib/postgresql.');
        }

        return $match[1];
    }

    /**
     * @return list<string> The running containers that can reach the server's PostgreSQL (sites using
     *                      it, and anything else on its network), but the server's own
     */
    public function clients(): array
    {
        $clients = [];

        foreach ($this->lines($this->docker(['ps', '--filter', 'network='.self::NETWORK, '--format', '{{.ID}} {{.Names}} {{.Label "com.docker.compose.project"}}'])->orFail('Listing what uses the server\'s PostgreSQL')) as $line) {
            [$id, $name, $project] = explode(' ', $line.'  ', 4);

            if (self::PROJECT !== $project && self::BESIDE !== $name && 1 === preg_match('/^[0-9a-f]{12,64}\z/', $id)) {
                $clients[] = $id;
            }
        }

        return $clients;
    }

    /**
     * The major of the PostgreSQL tools in each site's web container (its runtime's pg_dump, which
     * quire:backup runs), by container name: null when it has none.
     *
     * @return array<string, ?int>
     */
    public function siteTools(): array
    {
        $tools = [];

        foreach ($this->lines($this->docker(['ps', '--format', '{{.Names}}', '--filter', 'network='.self::NETWORK, '--filter', 'label=com.docker.compose.service=php'])->orFail('Listing the sites that use the server\'s PostgreSQL')) as $name) {
            $version = $this->docker(['exec', $name, 'pg_dump', '--version'], 60);
            $tools[$name] = $version->successful() && 1 === preg_match('/\(PostgreSQL\) (\d+)/', $version->output, $match) ? (int) $match[1] : null;
        }

        return $tools;
    }

    /**
     * @param list<string> $containers
     */
    public function stop(array $containers): void
    {
        if ([] !== $containers) {
            $this->docker(['stop', '--timeout', '30', ...$containers], 600)->orFail('Stopping the sites that use the server\'s PostgreSQL');
        }
    }

    /**
     * @param list<string> $containers
     */
    public function start(array $containers): void
    {
        if ([] !== $containers) {
            $this->docker(['start', ...$containers], 600)->orFail('Starting the sites that use the server\'s PostgreSQL');
        }
    }

    /**
     * @return int The space left on the volume, in bytes
     */
    public function free(string $image, string $volume): int
    {
        $lines = $this->lines($this->helper($image, $volume, 'df', ['-Pk', self::MOUNT])->orFail('Measuring the space left for PostgreSQL'));

        // "Filesystem 1024-blocks Used Available Capacity Mounted on", then the volume's line
        $fields = preg_split('/\s+/', $lines[1] ?? '');

        if (false === $fields || !isset($fields[3]) || !ctype_digit($fields[3])) {
            throw new \RuntimeException('The space left for PostgreSQL can\'t be measured.');
        }

        return (int) $fields[3] * 1024;
    }

    /**
     * @return list<int> The majors with a data folder on the volume
     */
    public function majorsOnVolume(string $image, string $volume): array
    {
        $majors = array_map(intval(...), array_filter($this->lines($this->helper($image, $volume, 'ls', ['-1', self::MOUNT])->orFail('Listing PostgreSQL\'s data folders')), ctype_digit(...)));
        sort($majors);

        return $majors;
    }

    /**
     * Deletes a major's data folder (never the one that runs: the caller checks).
     */
    public function removeMajor(string $image, string $volume, int $major): void
    {
        $this->helper($image, $volume, 'rm', ['-rf', '--', self::MOUNT.'/'.$this->major($major)])->orFail(\sprintf('Removing PostgreSQL %d\'s data', $major));
    }

    /**
     * Starts the new major beside the old one, on the same volume, in its own folder: an empty cluster
     * with the same superuser password and the server's authentication (SCRAM only); its own init
     * scripts don't run (the old one's roles come with the copy).
     *
     * @throws \RuntimeException When it doesn't start
     */
    public function startBeside(string $image, string $volume, int $major, int $wait): void
    {
        $this->docker([
            'run', '--detach', '--name', self::BESIDE,
            '--network', self::NETWORK,
            '--label', 'com.quirecms.server.database-upgrade='.$this->major($major),
            '--env', 'POSTGRES_PASSWORD',
            '--env', 'PGPASSWORD',
            '--env', 'PGDATA='.self::MOUNT.'/'.$this->major($major).'/docker',
            '--env', 'POSTGRES_INITDB_ARGS=--auth-host=scram-sha-256 --auth-local=scram-sha-256',
            '--volume', $volume.':'.self::MOUNT,
            '--security-opt', 'no-new-privileges:true',
            '--shm-size', '256m',
            $image,
        ], 300)->orFail(\sprintf('Starting PostgreSQL %d beside the server\'s', $major));

        for ($waited = 0;; $waited += 2) {
            if ($this->docker(['exec', self::BESIDE, 'pg_isready', '--host=127.0.0.1', '--username=postgres', '--quiet'], 30)->successful()) {
                return;
            }

            $running = trim($this->docker(['inspect', '--format', '{{.State.Running}}', self::BESIDE], 30)->output);

            if ('true' !== $running || $waited >= $wait) {
                $logs = $this->docker(['logs', '--tail', '20', self::BESIDE], 30);

                throw new \RuntimeException(\sprintf("PostgreSQL %d didn't start beside the server's:\n%s", $major, trim($logs->output.$logs->errorOutput)));
            }

            ($this->pause)(2);
        }
    }

    /**
     * Stops the new major beside the old one cleanly, and removes its container (its data stays).
     */
    public function removeBeside(): void
    {
        $exists = $this->docker(['inspect', '--format', '{{.Name}}', self::BESIDE], 30)->successful();

        if ($exists) {
            $this->docker(['stop', '--timeout', '120', self::BESIDE], 300)->orFail('Stopping the new PostgreSQL');
            $this->docker(['rm', '--force', '--volumes', self::BESIDE], 120)->orFail('Removing the new PostgreSQL\'s container');
        }
    }

    /**
     * Copies the old major's roles and these databases into the new one.
     *
     * @param list<string>           $databases
     * @param \Closure(string): void $output
     */
    public function copy(array $databases, \Closure $output): void
    {
        $this->docker(['exec', '--interactive', self::BESIDE, 'sh', '-s', '--', ...$databases], 6 * 3600, $output, self::COPY)->orFail('Copying into the new PostgreSQL');
    }

    /**
     * A query's rows (one per line, columns separated by "|"), on the old major (through the network)
     * or the new one, from the new one's container.
     */
    public function query(bool $old, string $database, string $sql): string
    {
        $host = $old ? ['--host=quire-database'] : [];

        return $this->docker(['exec', self::BESIDE, 'psql', '--no-psqlrc', '--no-align', '--tuples-only', '--username=postgres', ...$host, '--dbname='.$database, '--set=ON_ERROR_STOP=1', '--command='.$sql], 3600)
            ->orFail(\sprintf('Reading the %s PostgreSQL', $old ? 'old' : 'new'))->output;
    }

    /**
     * A container of the new or old major's image, on the volume, without a network, for one program.
     *
     * @param list<string> $arguments
     */
    private function helper(string $image, string $volume, string $program, array $arguments): ProcessResult
    {
        return $this->docker(['run', '--rm', '--network', 'none', '--security-opt', 'no-new-privileges:true', '--volume', $volume.':'.self::MOUNT, '--entrypoint', $program, $image, ...$arguments], 600);
    }

    private function major(int $major): string
    {
        if ($major < 10 || $major > 999) {
            throw new \InvalidArgumentException(\sprintf('%d isn\'t a PostgreSQL major version.', $major));
        }

        return (string) $major;
    }

    /**
     * @return list<string>
     */
    private function lines(ProcessResult $result): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $result->output)), static fn (string $line): bool => '' !== $line));
    }

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output
     */
    private function docker(array $arguments, float $timeout = 120, ?\Closure $output = null, ?string $input = null): ProcessResult
    {
        return $this->runner->run(['docker', ...$arguments], [
            'DOCKER_HOST' => $this->dockerHost,
            'POSTGRES_PASSWORD' => $this->postgresPassword,
            'PGPASSWORD' => $this->postgresPassword,
        ], timeout: $timeout, output: $output, input: $input);
    }
}
