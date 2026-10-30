<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Quire\Server\Git\Repository;
use Quire\Server\Operation\Operation;
use Quire\Server\Operation\OperationLog;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Process\ProcessRunner;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Registry\UpdatePolicies;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Update\AutomaticUpdates;
use Quire\Server\Update\SiteUpdater;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Backups off the server, with restic: every night (from QUIRE_SERVER_BACKUP_HOUR, UTC), each running
 * site (quire:backup: its database and files, and its folder) and the server's own (its registry, a
 * manifest of its sites, the front door's domains, the deploy keys), then the retention (each site's on its own); on Sundays, the storage only
 * forgotten backups used is freed, and a part of the stored data is read back to check it. A site
 * that fails doesn't stop the others; the night's operation says which.
 */
final readonly class OffsiteBackups
{
    public const string NIGHTLY = 'backups.nightly';
    public const string SITE_TAG = 'quire-site';
    public const string SERVER_TAG = 'quire-server';
    /** A Git mode site's: its code is its repository's, so it isn't in its backup */
    public const string GIT_TAG = 'git';
    /** The server's list of its sites, in its backup */
    private const string MANIFEST = 'sites.json';

    /** What a site's folder has that its build makes again: not in its backups */
    private const array BUILT = ['vendor', 'var', 'node_modules', 'public/assets', '.git'];
    private const int CHECK_PERCENT = 5;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $now
     */
    public function __construct(
        private BackupSettings $settings,
        private Restic $restic,
        private SiteRegistry $registry,
        private SiteManager $sites,
        private SiteLock $lock,
        private SiteUpdater $updater,
        private SiteGit $repositories,
        private UpdatePolicies $policies,
        private OperationLog $operations,
        private ServerPaths $paths,
        private ProcessRunner $runner,
        private string $dockerHost,
        private string $postgresAdminUrl,
        private ?\Closure $now = null,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function configured(): bool
    {
        return $this->settings->configured();
    }

    /**
     * Whether tonight's backups are due: set up, tonight's window open, and not run since it opened.
     */
    public function due(): bool
    {
        if (!$this->configured()) {
            return false;
        }

        $hour = AutomaticUpdates::parseHour($this->settings->hour, 'QUIRE_SERVER_BACKUP_HOUR');

        if (null === $hour) {
            return false;
        }

        return self::isDue($this->operations->lastStarted(self::NIGHTLY), $hour, $this->now());
    }

    /**
     * Due once a day: from the hour (UTC), when the last run started before it.
     */
    public static function isDue(?\DateTimeImmutable $last, int $hour, \DateTimeImmutable $now): bool
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $window = $now->setTime($hour, 0);

        return $now >= $window && (!$last instanceof \DateTimeImmutable || $last < $window);
    }

    /**
     * Every running site and the server, off the server; then the retention.
     *
     * @param \Closure(string): void $say
     *
     * @return array{backed_up: list<string>, failed: list<string>}
     */
    public function nightly(string $channel, string $requestedBy, \Closure $say): array
    {
        $this->settings->validate();
        $operation = $this->operations->start(self::NIGHTLY, null, [], $channel, $requestedBy);
        $this->secrets($operation);
        $log = static function (string $line) use ($operation, $say): void {
            $operation->line($line);
            $say($line);
        };
        $outcome = ['backed_up' => [], 'failed' => []];

        try {
            if ($this->settings->plainHttp()) {
                $log('The repository is reached over plain HTTP: its data is encrypted, but its keys travel in the clear. Use https://.');
            }

            if (!$this->restic->opens()) {
                throw new BackupFailed('The backup repository doesn\'t exist: "quire-server backups:setup" creates it.');
            }

            foreach ($this->registry->all() as $site) {
                if (Site::RUNNING !== $site->status) {
                    continue;
                }

                try {
                    $snapshot = $this->lock->run($site->name, fn (): string => $this->backUpSite($site));
                    $log(\sprintf('%s: backed up (%s)', $site->name, substr($snapshot, 0, 8)));
                    $outcome['backed_up'][] = $site->name->value;
                } catch (\Throwable $e) {
                    $log(\sprintf('%s: FAILED: %s', $site->name, $e->getMessage()));
                    $outcome['failed'][] = $site->name->value;
                }
            }

            try {
                $snapshot = $this->backUpServer();
                $log(\sprintf('The server: backed up (%s)', substr($snapshot, 0, 8)));
                $outcome['backed_up'][] = 'server';
            } catch (\Throwable $e) {
                $log('The server: FAILED: '.$e->getMessage());
                $outcome['failed'][] = 'server';
            }

            $sunday = '7' === $this->now()->setTimezone(new \DateTimeZone('UTC'))->format('N');
            $this->restic->forget(prune: $sunday);
            $log($sunday ? 'Old backups forgotten, and their storage freed' : 'Old backups forgotten');

            if ($sunday) {
                $check = $this->restic->check(self::CHECK_PERCENT);
                $log($check->successful() ? \sprintf('The repository checks out (%d%% of its data read back)', self::CHECK_PERCENT) : 'The repository DOESN\'T check out: "quire-server backups:check" says why');

                if (!$check->successful()) {
                    $outcome['failed'][] = 'check';
                }
            }
        } catch (\Throwable $e) {
            $operation->fail($e->getMessage());

            throw $e instanceof BackupFailed ? $e : new BackupFailed($e->getMessage(), 0, $e);
        }[] === $outcome['failed'] ? $operation->succeed() : $operation->fail('Not backed up: '.implode(', ', $outcome['failed']));

        return $outcome;
    }

    /**
     * One site, off the server, now.
     *
     * @param \Closure(string): void $say
     */
    public function backUpNow(SiteName $name, string $channel, string $requestedBy, \Closure $say): string
    {
        $this->settings->validate();
        $site = $this->registry->get($name);

        if (!$site instanceof Site || Site::RUNNING !== $site->status) {
            throw new \DomainException(\sprintf('There\'s no running site named "%s".', $name));
        }

        $operation = $this->operations->start('site.backup', $name, ['off_server' => true], $channel, $requestedBy);
        $this->secrets($operation);

        try {
            $say('Backing it up, and sending it off the server (encrypted)');
            $snapshot = $this->lock->run($name, fn (): string => $this->backUpSite($site));
            $operation->line('Snapshot '.$snapshot);
        } catch (\Throwable $e) {
            $operation->fail($e->getMessage());

            throw $e;
        }

        $operation->succeed();

        return $snapshot;
    }

    /**
     * A site's backups off the server (or the server's own, with null), newest first: this server's,
     * or another's that backs up to the same repository (its QUIRE_SERVER_BACKUP_HOST).
     *
     * @return list<array{id: string, time: string, tags: list<string>, paths: list<string>}>
     */
    public function list(?SiteName $site, ?string $server = null): array
    {
        $this->settings->validate();

        return array_reverse($this->restic->snapshots($site instanceof SiteName ? [self::SITE_TAG, 'site:'.$site->value] : [self::SERVER_TAG], $server));
    }

    /**
     * Restores a site from one of its backups off the server ("latest", or a snapshot's ID): the site
     * is backed up as it is first, and put back if it doesn't answer after.
     *
     * @param \Closure(string): void $say
     *
     * @return array{id: string, time: string} The snapshot restored
     */
    public function restore(SiteName $name, string $snapshot, string $channel, string $requestedBy, \Closure $say): array
    {
        $this->settings->validate();

        if (1 !== preg_match(Restic::SNAPSHOT, $snapshot)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a backup: "latest", or an ID from "quire-server backups %s".', SiteName::printable($snapshot), $name));
        }

        $snapshots = $this->list($name);
        $chosen = array_find($snapshots, static fn ($candidate): bool => 'latest' === $snapshot || str_starts_with($candidate['id'], $snapshot));

        // Only one of the site's own: an ID of another site's backup isn't found among these.
        $file = null === $chosen ? null : $this->backupFile($chosen['paths']);

        if (null === $chosen || null === $file) {
            throw new \DomainException(\sprintf('The site "%s" has no such backup off the server ("quire-server backups %1$s" lists them).', $name));
        }

        $into = $this->paths->backups($name).'/from-off-server-'.substr($chosen['id'], 0, 8);
        $this->filesystem->remove($into);
        $this->filesystem->mkdir($into, 0o700);

        try {
            $say(\sprintf('Getting its backup of %s from off the server', $chosen['time']));
            // With the rest of its database, when the backup has it (made by Quire Server 0.3.1 and later)
            $this->restic->restorePaths($chosen['id'], [$file, ...$this->dumpOf($chosen['paths'], $file)], $into);
            $file = is_file($into.$file) ? $into.$file : throw new BackupFailed('The snapshot doesn\'t have its backup file.');
            $this->updater->restoreBackup($name, $file, ['snapshot' => $chosen['id'], 'from' => 'off the server'], $channel, $requestedBy, $say);
        } finally {
            $this->filesystem->remove($into);
        }

        return ['id' => $chosen['id'], 'time' => $chosen['time']];
    }

    /**
     * A new site from a site's backup off the server (of this server, or another's in the same
     * repository: moving a site): its code as backed up, its data restored, its settings its own.
     *
     * @param list<Domain>           $domains
     * @param \Closure(string): void $say
     *
     * @return array{id: string, time: string} The snapshot restored
     */
    public function restoreAsNew(SiteName $source, string $snapshot, SiteName $name, array $domains, ?string $server, string $channel, string $requestedBy, \Closure $say): array
    {
        $this->settings->validate();

        if (1 !== preg_match(Restic::SNAPSHOT, $snapshot)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a backup: "latest", or an ID from "quire-server backups %s".', SiteName::printable($snapshot), $source));
        }
        $chosen = array_find($this->list($source, $server), static fn ($candidate): bool => 'latest' === $snapshot || str_starts_with($candidate['id'], $snapshot));

        if (null === $chosen) {
            throw new \DomainException(\sprintf('There\'s no such backup of "%s" off the server%s.', $source, null === $server ? '' : ' (from '.$server.')'));
        }

        if (\in_array(self::GIT_TAG, $chosen['tags'], true)) {
            throw new \DomainException(\sprintf('"%s" follows a Git repository: its code is there, not in its backups. Add it from its repository ("site:add %s --git=…"), then restore its data ("backups:restore %2$s"); or restore its whole server ("backups:restore-server").', $source, $name));
        }

        $this->restoreSite($source, $chosen, $name, $domains, null, false, $server, $channel, $requestedBy, $say);

        return ['id' => $chosen['id'], 'time' => $chosen['time']];
    }

    /**
     * Every site another server had when it was last backed up (its manifest), each restored as a new
     * site of this one from its latest backup: the same name and domains, its update policy, a Git
     * mode site from its repository (with its deploy key). Those whose name is taken here are left
     * out; one that fails doesn't stop the others.
     *
     * @param \Closure(string): void $say
     *
     * @return list<array{site: string, outcome: string, detail: string}>
     */
    public function restoreServer(string $server, string $channel, string $requestedBy, \Closure $say): array
    {
        $manifest = $this->manifest($server);
        $outcomes = [];

        try {
            foreach ($manifest['sites'] as $entry) {
                $name = new SiteName($entry['name']);
                $say(\sprintf('"%s"', $name));

                try {
                    $existing = $this->registry->get($name);

                    if ($existing instanceof Site && Site::REMOVED !== $existing->status) {
                        $outcomes[] = ['site' => $name->value, 'outcome' => 'skipped', 'detail' => 'a site of this server has its name'];

                        continue;
                    }

                    $chosen = $this->list($name, $server)[0] ?? throw new \DomainException('it has no backup');
                    $repository = null;

                    if (null !== $entry['git']) {
                        $repository = new Repository($entry['git']['url'], $entry['git']['branch']);
                        $this->restoreKey($manifest['keys'], $name);
                    }

                    $this->restoreSite($name, $chosen, $name, array_map(static fn (string $domain): Domain => new Domain($domain), $entry['domains']), $repository, $entry['git']['poll'] ?? false, $server, $channel, $requestedBy, static fn (string $step) => $say('  '.$step));
                    $this->policies->set($name, $entry['updates']);
                    $outcomes[] = ['site' => $name->value, 'outcome' => 'restored', 'detail' => 'as of '.$chosen['time']];
                } catch (\Throwable $e) {
                    $outcomes[] = ['site' => $name->value, 'outcome' => 'failed', 'detail' => $e->getMessage()];
                }
            }
        } finally {
            $this->filesystem->remove($manifest['into']);
        }

        return $outcomes;
    }

    /**
     * The sites another server had when it was last backed up, from its manifest (with its deploy
     * keys, got with it into a folder of their own: remove it after).
     *
     * @return array{sites: list<array{name: string, domains: list<string>, git: array{url: string, branch: string, poll: bool}|null, updates: string}>, keys: string, into: string, time: string}
     */
    public function manifest(string $server): array
    {
        $this->settings->validate();
        $chosen = $this->list(null, $server)[0] ?? throw new \DomainException(\sprintf('There\'s no backup of the server "%s" off the server.', $server));
        $file = null;
        $keys = null;

        foreach ($chosen['paths'] as $path) {
            if (str_ends_with($path, '/'.self::MANIFEST)) {
                $file = $path;
            } elseif (str_ends_with($path, '/server/keys')) {
                $keys = $path;
            }
        }

        if (null === $file) {
            throw new \DomainException(\sprintf('The server "%s"\'s latest backup has no list of its sites (it was made before servers backed it up): restore its sites one by one ("backups:restore <site> --from-server=%1$s --as=…").', $server));
        }

        $into = $this->paths->root.'/backups/.restoring-server-'.bin2hex(random_bytes(4));
        $this->filesystem->mkdir($into, 0o700);

        try {
            $this->restic->restorePaths($chosen['id'], null === $keys ? [$file] : [$file, $keys], $into);
            $data = json_decode((string) file_get_contents($into.$file), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->filesystem->remove($into);

            throw $e instanceof \JsonException ? new BackupFailed('The server\'s list of its sites isn\'t one this server reads.', 0, $e) : $e;
        }

        $sites = [];

        foreach (\is_array($data) && \is_array($data['sites'] ?? null) ? $data['sites'] : [] as $site) {
            if (!\is_array($site) || !\is_string($site['name'] ?? null) || !\is_array($site['domains'] ?? null) || !\is_string($site['updates'] ?? null) || !\in_array($site['updates'], UpdatePolicies::POLICIES, true)) {
                continue;
            }

            $git = \is_array($site['git'] ?? null) && \is_string($site['git']['url'] ?? null) && \is_string($site['git']['branch'] ?? null)
                ? ['url' => $site['git']['url'], 'branch' => $site['git']['branch'], 'poll' => true === ($site['git']['poll'] ?? false)]
                : null;
            $sites[] = ['name' => $site['name'], 'domains' => array_values(array_filter($site['domains'], is_string(...))), 'git' => $git, 'updates' => $site['updates']];
        }

        return ['sites' => $sites, 'keys' => null === $keys ? '' : $into.$keys, 'into' => $into, 'time' => $chosen['time']];
    }

    /**
     * Restores a site's backup as a new site.
     *
     * @param array{id: string, time: string, tags: list<string>, paths: list<string>} $chosen
     * @param list<Domain>                                                             $domains
     * @param \Closure(string): void                                                   $say
     */
    private function restoreSite(SiteName $source, array $chosen, SiteName $name, array $domains, ?Repository $repository, bool $poll, ?string $server, string $channel, string $requestedBy, \Closure $say): void
    {
        $file = $this->backupFile($chosen['paths']);
        $folder = null;

        foreach ($chosen['paths'] as $path) {
            if (1 === preg_match('#^/.+/sites/'.preg_quote($source->value, '#').'\z#', $path)) {
                $folder = $path;
            }
        }

        if (null === $file || (null === $folder && !$repository instanceof Repository)) {
            throw new \DomainException('That backup was made before sites\' folders were backed up: it can only be restored to its site ("backups:restore" without --as).');
        }

        $into = $this->paths->root.'/backups/.restoring-'.$name->value.'-'.bin2hex(random_bytes(4));
        $this->filesystem->mkdir($into, 0o700);

        try {
            $say(\sprintf('Getting the backup of "%s" of %s from off the server', $source, $chosen['time']));
            $this->restic->restorePaths($chosen['id'], [$file, ...$this->dumpOf($chosen['paths'], $file), ...($repository instanceof Repository ? [] : [$folder])], $into);
            $from = ['snapshot' => $chosen['id'], 'from_site' => $source->value, 'from_server' => $server ?? $this->settings->host];
            $this->sites->restoreAsNew($name, $domains, $repository ?? $into.$folder, $into.$file, $from, $channel, $requestedBy, $say, $poll);
        } finally {
            $this->filesystem->remove($into);
        }
    }

    /**
     * A Git mode site's deploy key, from its old server's backup, where this server keeps it (unless
     * it has one already).
     */
    private function restoreKey(string $keys, SiteName $name): void
    {
        $from = $keys.'/'.$name->value;
        $to = $this->paths->root.'/server/keys/'.$name->value;

        if ('' === $keys || !is_dir($from) || file_exists($to)) {
            return;
        }

        $this->filesystem->mirror($from, $to);
        $this->filesystem->chmod($to, 0o700);

        $files = glob($to.'/*');

        foreach (false === $files ? [] : $files as $file) {
            $this->filesystem->chmod($file, 0o600);
        }
    }

    /**
     * The database dump that goes with a snapshot's quire:backup file, if the snapshot has one.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function dumpOf(array $paths, string $file): array
    {
        return \in_array(SiteDatabaseDumps::of($file), $paths, true) ? [SiteDatabaseDumps::of($file)] : [];
    }

    /**
     * The quire:backup file among a snapshot's paths.
     *
     * @param list<string> $paths
     */
    private function backupFile(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (str_ends_with($path, '.tar')) {
                return $path;
            }
        }

        return null;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->now instanceof \Closure ? ($this->now)() : new \DateTimeImmutable();
    }

    /**
     * Its data (quire:backup), and its folder (its code and files, but what a build makes again; a
     * Git mode site's code is its repository's).
     */
    private function backUpSite(Site $site): string
    {
        $file = $this->sites->backup($site->name, $this->sites->projectOf($site), 'off-server');
        $dump = SiteDatabaseDumps::of($file);
        $folder = $this->paths->site($site->name);
        $git = null !== $this->repositories->of($site->name);

        try {
            return $this->restic->backup(
                $git ? [$file, $dump] : [$file, $dump, $folder],
                [self::SITE_TAG, 'site:'.$site->name->value, ...($git ? [self::GIT_TAG] : [])],
                array_map(static fn (string $built): string => $folder.'/'.$built, self::BUILT),
            );
        } finally {
            $this->filesystem->remove([$file, $dump]);
        }
    }

    /**
     * The server's registry (pg_dump in its PostgreSQL's container), the front door's domains and Git
     * mode sites' deploy keys.
     */
    private function backUpServer(): string
    {
        $directory = $this->paths->root.'/backups/server';
        $this->filesystem->mkdir($directory, 0o700);
        $dump = $directory.'/registry.dump';
        $container = trim($this->docker(['ps', '--quiet', '--filter', 'label=com.docker.compose.project=quire-server', '--filter', 'label=com.docker.compose.service=database'])->output);

        if (1 !== preg_match('/^[0-9a-f]{12,64}\z/', $container)) {
            throw new BackupFailed('The server\'s PostgreSQL isn\'t running.');
        }

        $password = parse_url($this->postgresAdminUrl, \PHP_URL_PASS);
        $result = $this->docker(['exec', '-e', 'PGPASSWORD', $container, 'pg_dump', '-U', 'postgres', '--format=custom', 'quire_server'], ['PGPASSWORD' => \is_string($password) ? rawurldecode($password) : '']);

        if (!$result->successful()) {
            throw new BackupFailed('Dumping the server\'s registry failed: '.trim($result->errorOutput));
        }

        $previous = umask(0o077);

        try {
            file_put_contents($dump, $result->output);
        } finally {
            umask($previous);
        }

        // What a new server needs to restore it all: its sites, their domains, Git settings and policies
        $manifest = $directory.'/'.self::MANIFEST;
        $sites = [];

        foreach ($this->registry->all() as $site) {
            if (Site::RUNNING !== $site->status) {
                continue;
            }

            $git = $this->repositories->of($site->name);
            $sites[] = [
                'name' => $site->name->value,
                'domains' => array_map(static fn (Domain $domain): string => $domain->value, $site->domains),
                'git' => null === $git ? null : ['url' => $git['repository']->url, 'branch' => $git['repository']->branch, 'poll' => $git['poll']],
                'updates' => $this->policies->of($site->name),
            ];
        }

        file_put_contents($manifest, json_encode(['schema' => 1, 'sites' => $sites], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
        chmod($manifest, 0o600);

        try {
            return $this->restic->backup(array_values(array_filter([$dump, $manifest, $this->paths->domains(), $this->paths->root.'/server/keys'], file_exists(...))), [self::SERVER_TAG]);
        } finally {
            $this->filesystem->remove([$dump, $manifest]);
        }
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    private function docker(array $arguments, array $env = []): \Quire\Server\Process\ProcessResult
    {
        return $this->runner->run(['docker', ...$arguments], ['DOCKER_HOST' => $this->dockerHost, ...$env], timeout: 600);
    }

    private function secrets(Operation $operation): void
    {
        foreach ($this->settings->secrets() as $secret) {
            $operation->secret($secret);
        }
    }
}
