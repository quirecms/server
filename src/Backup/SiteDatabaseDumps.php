<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Quire\Server\Docker\Compose;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessRunner;
use Quire\Server\Site\EnvFile;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;

/**
 * What a site keeps in its database besides Quire's own schema, which quire:backup has: an
 * application's tables, in "public" or schemas of its own. A dump of the rest of the site's database
 * lies next to each of its backups ("<kind>-<time>.database.dump", pg_dump's custom format), and is
 * restored after quire:restore. Both run in the PostgreSQL that holds the site's database (its tools
 * are of its major), as the site's own role: what a dump holds can do nothing beyond that database.
 */
final readonly class SiteDatabaseDumps
{
    /** Where a dump is made in PostgreSQL's container, before it's copied out */
    private const string INSIDE = '/tmp/quire-site-database-';

    public function __construct(
        private ProcessRunner $runner,
        private Compose $compose,
        private string $dockerHost,
    ) {
    }

    /**
     * The dump that goes with a backup file.
     */
    public static function of(string $backup): string
    {
        return (str_ends_with($backup, '.tar') ? substr($backup, 0, -4) : $backup).'.database.dump';
    }

    /**
     * @param string $mode Site::SHARED_DATABASE or Site::OWN_DATABASE
     */
    public function dump(SiteProject $project, string $mode, string $file): void
    {
        $inside = self::INSIDE.bin2hex(random_bytes(6)).'.dump';
        $options = ['--format=custom', '--exclude-schema=quire', '--no-owner', '--no-privileges', '--file='.$inside];

        if (Site::OWN_DATABASE === $mode) {
            try {
                $this->compose->exec($project, 'database', ['sh', '-c', 'pg_dump -U "$POSTGRES_USER" "$@" "$POSTGRES_DB"', 'pg_dump', ...$options], timeout: 3600)->orFail('Dumping the site\'s database');
                $this->compose->copyFrom($project, 'database', $inside, $file);
            } finally {
                $this->compose->exec($project, 'database', ['rm', '-f', $inside], timeout: 60);
            }
        } else {
            [$container, $role, $password] = $this->shared($project);

            try {
                $this->docker(['exec', '-e', 'PGPASSWORD', $container, 'pg_dump', '--host=127.0.0.1', '--username='.$role, ...$options, $role], $password, 3600)->orFail('Dumping the site\'s database');
                $this->docker(['cp', '--quiet', $container.':'.$inside, $file], null, 3600)->orFail('Copying the site\'s database dump');
            } finally {
                $this->docker(['exec', $container, 'rm', '-f', $inside], null, 60);
            }
        }

        chmod($file, 0o600);
    }

    /**
     * Replaces what the dump has in the site's database (the objects it holds are dropped first; Quire's
     * schema isn't among them).
     *
     * @param string                        $mode   Site::SHARED_DATABASE or Site::OWN_DATABASE
     * @param (\Closure(string): void)|null $output
     */
    public function restore(SiteProject $project, string $mode, string $file, ?\Closure $output = null): void
    {
        $options = ['--clean', '--if-exists', '--no-owner', '--no-privileges', '--exit-on-error'];

        if (Site::OWN_DATABASE === $mode) {
            $this->compose->exec($project, 'database', ['sh', '-c', 'pg_restore -U "$POSTGRES_USER" --dbname="$POSTGRES_DB" "$@"', 'pg_restore', ...$options], $output, 3600, $file)->orFail('Restoring the site\'s database');

            return;
        }

        [$container, $role, $password] = $this->shared($project);
        $this->docker(['exec', '--interactive', '-e', 'PGPASSWORD', $container, 'pg_restore', '--host=127.0.0.1', '--username='.$role, '--dbname='.$role, ...$options], $password, 3600, $file, $output)->orFail('Restoring the site\'s database');
    }

    /**
     * The server's PostgreSQL container, and the site's role (its database's name too) with its password.
     *
     * @return array{string, string, string}
     */
    private function shared(SiteProject $project): array
    {
        $container = trim($this->docker(['ps', '--quiet', '--filter', 'label=com.docker.compose.project=quire-server', '--filter', 'label=com.docker.compose.service=database'], null, 60)->output);

        if (1 !== preg_match('/^[0-9a-f]{12,64}\z/', $container)) {
            throw new BackupFailed('The server\'s PostgreSQL isn\'t running.');
        }

        $password = EnvFile::read($project->envFile)->get('QUIRE_DATABASE_PASSWORD') ?? throw new BackupFailed('The site\'s prod.env has no QUIRE_DATABASE_PASSWORD.');

        return [$container, new SiteName($project->name())->databaseIdentifier(), $password];
    }

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output
     */
    private function docker(array $arguments, ?string $password, float $timeout, ?string $inputFile = null, ?\Closure $output = null): ProcessResult
    {
        return $this->runner->run(['docker', ...$arguments], ['DOCKER_HOST' => $this->dockerHost, ...(null === $password ? [] : ['PGPASSWORD' => $password])], timeout: $timeout, output: $output, inputFile: $inputFile);
    }
}
