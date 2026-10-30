<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

use Quire\Server\Backup\SiteDatabaseDumps;
use Quire\Server\Database\SiteDatabases;
use Quire\Server\Docker\Compose;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Docker\SkeletonInstaller;
use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Git\DeployKeys;
use Quire\Server\Git\Git;
use Quire\Server\Git\Repository;
use Quire\Server\Process\Durable;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\EnvFile;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteFiles;
use Quire\Server\Site\SiteName;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Adds and removes sites: each a recorded operation, one at a time per site, in steps. Adding a site
 * that fails part way undoes what it did; removing one always backs it up first and archives its
 * folder instead of deleting it.
 */
final readonly class SiteManager
{
    private const string PHP = 'php';

    public function __construct(
        private SiteRegistry $registry,
        private OperationLog $operations,
        private SiteLock $lock,
        private SkeletonInstaller $skeleton,
        private SiteDatabases $databases,
        private SiteFiles $files,
        private Compose $compose,
        private DomainDirectory $frontDoor,
        private ServerPaths $paths,
        private Git $git,
        private SiteGit $repositories,
        private DeployKeys $keys,
        private SiteDatabaseDumps $dumps,
        private string $mailerDsn = '',
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param \Closure(string): void $say What to tell the person who asked, as it happens
     */
    public function add(SiteRequest $request, string $channel, string $requestedBy, \Closure $say): AddedSite
    {
        return $this->lock->run($request->name, function () use ($request, $channel, $requestedBy, $say): AddedSite {
            $operation = $this->operations->start('site.add', $request->name, [
                'domains' => array_map(static fn (Domain $domain): string => $domain->value, $request->domains),
                'title' => $request->title,
                'admin_email' => $request->adminEmail,
                'invite' => $request->invite,
                'quire_version' => $request->repository instanceof Repository ? null : $request->quireVersion,
                'repository' => $request->repository?->url,
                'branch' => $request->repository?->branch,
            ], $channel, $requestedBy);
            $directory = $this->paths->site($request->name);
            $undo = [];
            $opened = [];
            $step = static function (string $what) use ($operation, $say): void {
                $operation->line('== '.$what);
                $say($what);
            };

            try {
                // Checked before anything is done, so undoing never touches what was there before.
                if (file_exists($directory)) {
                    throw new \DomainException(\sprintf('The folder "%s" exists already: move it away first (if an add of "%s" was cut short, "quire-server site:remove %2$s --skip-backup" clears what it left).', $directory, $request->name));
                }

                if ($request->repository instanceof Repository && $request->repository->overSsh() && !$this->keys->exists($request->name)) {
                    throw new \DomainException(\sprintf('The site has no deploy key yet: create it with "quire-server site:key %s", add it to the repository as a read-only deploy key, then add the site.', $request->name));
                }

                $step('Recording the site');
                $this->registry->add($request->name, $request->domains, Site::SHARED_DATABASE, $request->name->value);
                // Undone, it's removed: its name and domains are free to try again; its history stays.
                $undo[] = fn () => $this->registry->setStatus($request->name, Site::REMOVED);

                if ($request->repository instanceof Repository) {
                    $this->repositories->follow($request->name, $request->repository, $request->poll);
                    $undo[] = fn () => $this->repositories->forget($request->name);

                    $step(\sprintf('Getting its repository (%s, branch %s)', $request->repository->url, $request->repository->branch));
                    $undo[] = fn () => $this->archive($request->name, $directory);
                    $this->git->clone($request->repository, $request->name, $directory, $operation->output(...));

                    if (!is_file($directory.'/composer.lock')) {
                        $operation->line('Note: the repository has no composer.lock: each build resolves the versions anew. Commit one to build the same versions every time.');
                    }
                } else {
                    $step(\sprintf('Getting Quire %s', $request->quireVersion));
                    $undo[] = fn () => $this->archive($request->name, $directory);
                    $this->skeleton->create($directory, $request->quireVersion, $operation->output(...));
                }

                $step('Creating its database');
                $databasePassword = $this->databases->create($request->name);
                $operation->secret($databasePassword);
                $undo[] = fn () => $this->databases->drop($request->name);

                $step('Writing its settings');
                $secret = bin2hex(random_bytes(32));
                $operation->secret($secret);
                $this->files->writeEnv($directory, $request->name, $request->domains[0], array_filter([
                    'APP_SECRET' => $secret,
                    'QUIRE_DATABASE_PASSWORD' => $databasePassword,
                    // Asked for by the skeleton's production setup; its own PostgreSQL doesn't run here.
                    'POSTGRES_PASSWORD' => bin2hex(random_bytes(24)),
                    'MAILER_DSN' => $this->mailerDsn,
                ], static fn (string $value): bool => '' !== $value));
                $this->files->writeOverride($directory, $request->name, $request->domains, Site::SHARED_DATABASE);
                $project = $this->project($request->name, $request->domains, $request->name->value, Site::SHARED_DATABASE);

                $step('Checking and building it (a few minutes)');
                $undo[] = fn () => $this->compose->down($project, volumes: true);
                $this->compose->build($project, $operation->output(...));

                $step('Starting it');
                $this->compose->up($project, $operation->output(...));

                $step('Installing it');
                $adminPassword = $this->install($project, $request, $operation);

                if ($request->repository instanceof Repository) {
                    // What it runs of its branch; a site in Git mode keeps its repository's lock.
                    $this->repositories->deployed($request->name, $this->git->head($request->repository, $request->name, $directory));
                } else {
                    $this->pinComposerLock($request->name, $project);
                }

                $step('Opening it to visitors');
                // Running first: the agent's rounds open the domains of running sites, and close others.
                $this->registry->setStatus($request->name, Site::RUNNING);

                foreach ($request->domains as $domain) {
                    $this->frontDoor->allow($domain, $request->name);
                    $opened[] = $domain;
                }

                $operation->succeed();

                return new AddedSite($this->registry->get($request->name) ?? throw new \LogicException('The site was just added.'), $adminPassword);
            } catch (\Throwable $e) {
                $this->undo($operation, $e, $undo, $opened);
            }
        });
    }

    /**
     * Adds a site from a backup (e.g. one from off the server, of a site of this server or another):
     * its code and files as the backup's folder has them, or (Git mode) its repository's branch; its
     * settings written anew (its own database and password, a new secret); its database and stored
     * files restored from the backup. If anything fails, everything done is undone.
     *
     * @param list<Domain>           $domains
     * @param string|Repository      $code    The site's folder, as backed up, or its repository
     * @param string                 $backup  Its quire:backup file
     * @param array<string, scalar>  $from    What's recorded of where it came from
     * @param \Closure(string): void $say
     */
    public function restoreAsNew(SiteName $name, array $domains, string|Repository $code, string $backup, array $from, string $channel, string $requestedBy, \Closure $say, bool $poll = false): Site
    {
        return $this->lock->run($name, function () use ($name, $domains, $code, $backup, $from, $channel, $requestedBy, $say, $poll): Site {
            $operation = $this->operations->start('site.restore_as_new', $name, ['domains' => array_map(static fn (Domain $domain): string => $domain->value, $domains), ...$from], $channel, $requestedBy);
            $directory = $this->paths->site($name);
            $undo = [];
            $opened = [];
            $step = static function (string $what) use ($operation, $say): void {
                $operation->line('== '.$what);
                $say($what);
            };

            try {
                if ([] === $domains) {
                    throw new \InvalidArgumentException('A site needs at least one domain.');
                }

                if (file_exists($directory)) {
                    throw new \DomainException(\sprintf('The folder "%s" exists already: choose another name, or move it away first.', $directory));
                }

                if (\is_string($code)) {
                    foreach (['compose.yaml', 'compose.prod.yaml', 'composer.json'] as $file) {
                        if (!is_file($code.'/'.$file)) {
                            throw new \DomainException(\sprintf('The backup\'s folder has no %s: it isn\'t a Quire site\'s.', $file));
                        }
                    }
                } elseif ($code->overSsh() && !$this->keys->exists($name)) {
                    throw new \DomainException(\sprintf('The site has no deploy key: create it with "quire-server site:key %s" and add it to the repository first.', $name));
                }

                $step('Recording the site');
                $this->registry->add($name, $domains, Site::SHARED_DATABASE, $name->value);
                $undo[] = fn () => $this->registry->setStatus($name, Site::REMOVED);
                $undo[] = fn () => $this->archive($name, $directory);

                $own = [];

                if (\is_string($code)) {
                    $step('Putting its code in place, as the backup has it');
                    $this->filesystem->mirror($code, $directory);
                    // Its owner's own settings (e.g. a storage disk, an SMTP server): its server's are
                    // this server's to write, its secrets new.
                    $own = is_file($directory.'/'.SiteFiles::ENV_FILE) ? SiteFiles::ownSettings(EnvFile::read($directory.'/'.SiteFiles::ENV_FILE)) : [];

                    foreach ($own as $value) {
                        $operation->secret($value);
                    }

                    $this->filesystem->remove(array_map(static fn (string $file): string => $directory.'/'.$file, [SiteFiles::ENV_FILE, SiteFiles::ENV_FILE.'.before-server', SiteFiles::OVERRIDE]));
                } else {
                    $this->repositories->follow($name, $code, $poll);
                    $undo[] = fn () => $this->repositories->forget($name);
                    $step(\sprintf('Getting its repository (%s, branch %s)', $code->url, $code->branch));
                    $this->git->clone($code, $name, $directory, $operation->output(...));
                }

                $step('Creating its database');
                $databasePassword = $this->databases->create($name);
                $operation->secret($databasePassword);
                $undo[] = fn () => $this->databases->drop($name);

                $step('Writing its settings');
                $secret = bin2hex(random_bytes(32));
                $operation->secret($secret);
                $this->files->writeEnv($directory, $name, $domains[0], array_filter([
                    'MAILER_DSN' => $this->mailerDsn,
                    ...$own,
                    'APP_SECRET' => $secret,
                    'QUIRE_DATABASE_PASSWORD' => $databasePassword,
                    'POSTGRES_PASSWORD' => bin2hex(random_bytes(24)),
                ], static fn (string $value): bool => '' !== $value));
                $this->files->writeOverride($directory, $name, $domains, Site::SHARED_DATABASE);
                $project = $this->project($name, $domains, $name->value, Site::SHARED_DATABASE);

                $step('Checking and building it (a few minutes)');
                $undo[] = fn () => $this->compose->down($project, volumes: true);
                $this->compose->build($project, $operation->output(...));

                $step('Starting it');
                $this->compose->up($project, $operation->output(...));

                $step('Restoring its database and files from the backup');
                $this->compose->exec($project, self::PHP, ['bin/console', 'quire:restore', '-', '--force', '--no-interaction'], $operation->output(...), 3600, $backup)->orFail('Restoring the backup');
                $this->restoreDatabase($name, $project, $backup, $operation->output(...));

                if ($code instanceof Repository) {
                    $this->repositories->deployed($name, $this->git->head($code, $name, $directory));
                } else {
                    $this->pinComposerLock($name, $project);
                }

                $step('Opening it to visitors');
                $this->registry->setStatus($name, Site::RUNNING);

                foreach ($domains as $domain) {
                    $this->frontDoor->allow($domain, $name);
                    $opened[] = $domain;
                }

                $operation->succeed();

                return $this->registry->get($name) ?? throw new \LogicException('The site was just added.');
            } catch (\Throwable $e) {
                $this->undo($operation, $e, $undo, $opened);
            }
        });
    }

    /**
     * Undoes what was done, last first, and the domains opened; records the failure and throws it on.
     *
     * @param list<\Closure(): mixed> $undo
     * @param list<Domain>            $opened
     */
    private function undo(Operation $operation, \Throwable $e, array $undo, array $opened): never
    {
        $operation->line('!! '.$e->getMessage());
        $operation->line('== Undoing what was done');

        foreach (array_reverse($undo) as $revert) {
            try {
                $revert();
            } catch (\Throwable $failure) {
                $operation->line('!! Undoing failed too: '.$failure->getMessage());
            }
        }

        // Only what this operation opened: a domain may belong to another site.
        foreach ($opened as $domain) {
            $this->frontDoor->revoke($domain);
        }

        $operation->fail($e->getMessage());

        throw $e;
    }

    /**
     * Takes over a site that runs on this machine already, from its folder in /srv/quire/sites (e.g. one
     * installed from the skeleton, which holds ports 80 and 443 itself): backed up first, then moved
     * behind the front door with as little change as can be. Its files stay as they are but two lines
     * of its prod.env (which Compose files, which project); compose.server.yaml is added; its database
     * stays its own. If anything fails, it's put back as it was, ports and all.
     *
     * @param list<Domain>           $domains Its domains; those of its SERVER_NAME when none are given
     * @param \Closure(string): void $say
     */
    public function adopt(SiteName $name, array $domains, string $channel, string $requestedBy, \Closure $say): Site
    {
        return $this->lock->run($name, function () use ($name, $domains, $channel, $requestedBy, $say): Site {
            $directory = $this->paths->site($name);
            $envPath = $directory.'/'.SiteFiles::ENV_FILE;
            $before = $envPath.'.before-server';

            foreach (['compose.yaml', 'compose.prod.yaml', SiteFiles::ENV_FILE] as $file) {
                if (!is_file($directory.'/'.$file)) {
                    throw new \DomainException(\sprintf('"%s" has no %s: only a Quire site set up for production (with prod.env) can be adopted.', $directory, $file));
                }
            }

            if (file_exists($directory.'/'.SiteFiles::OVERRIDE)) {
                throw new \DomainException(\sprintf('"%s" has a %s already: it\'s a site of a server.', $directory, SiteFiles::OVERRIDE));
            }

            $env = EnvFile::read($envPath);
            $project = $env->get('COMPOSE_PROJECT_NAME') ?? $name->value;

            if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,62}\z/', $project)) {
                throw new \DomainException(\sprintf('Its Compose project name "%s" isn\'t one this server can manage.', SiteName::printable($project)));
            }

            $domains = [] === $domains ? self::domainsOf($env->get('SERVER_NAME') ?? '') : $domains;

            if ([] === $domains) {
                throw new \DomainException('Its domains aren\'t known (it has no SERVER_NAME): pass them with --domain.');
            }

            $operation = $this->operations->start('site.adopt', $name, ['domains' => array_map(static fn (Domain $domain): string => $domain->value, $domains), 'project' => $project], $channel, $requestedBy);
            $site = $this->project($name, $domains, $project, Site::OWN_DATABASE);
            $undo = [];
            $step = static function (string $what) use ($operation, $say): void {
                $operation->line('== '.$what);
                $say($what);
            };

            foreach ($env->values() as $value) {
                $operation->secret($value);
            }

            try {
                $step('Recording the site');
                $this->registry->add($name, $domains, Site::OWN_DATABASE, $project);
                $undo[] = fn () => $this->registry->setStatus($name, Site::REMOVED);

                $step('Backing it up, as it is');
                $operation->line('Backed up to '.$this->backup($name, $site, 'before-server'));

                $step('Changing its settings (a copy of them is kept)');
                $this->files->write($before, $env->content(), 0o600);
                $files = $env->get('COMPOSE_FILE') ?? 'compose.yaml:compose.prod.yaml';
                $this->files->writeOverride($directory, $name, $domains, Site::OWN_DATABASE, $project);
                $this->files->write($envPath, $env->with([
                    'COMPOSE_FILE' => str_contains($files, SiteFiles::OVERRIDE) ? $files : $files.':'.SiteFiles::OVERRIDE,
                    'COMPOSE_PROJECT_NAME' => $project,
                ])->content(), 0o600);
                $undo[] = function () use ($directory, $envPath, $before, $site, $operation): void {
                    // Its own settings back, the override gone, and it started as it was, ports and all.
                    $this->filesystem->copy($before, $envPath, true);
                    $this->filesystem->remove($directory.'/'.SiteFiles::OVERRIDE);
                    $this->compose->restoreAsBefore($site, $before, $operation->output(...));
                };

                // Its image gets the site's own name (two sites would share "quire-php-prod" otherwise):
                // built while the site still runs as it was, so it's offline only while it restarts.
                $step('Checking and building it (a few minutes; it keeps running meanwhile)');
                $this->compose->build($site, $operation->output(...));

                $step('Moving it behind the front door (a few seconds offline)');
                $this->compose->up($site, $operation->output(...));
                $this->pinComposerLock($name, $site);

                $step('Opening it to visitors');
                $this->registry->setStatus($name, Site::RUNNING);

                foreach ($domains as $domain) {
                    $this->frontDoor->allow($domain, $name);
                }

                $operation->succeed();

                return $this->registry->get($name) ?? throw new \LogicException('The site was just adopted.');
            } catch (\Throwable $e) {
                $operation->line('!! '.$e->getMessage());
                $operation->line('== Putting it back as it was');

                foreach (array_reverse($undo) as $revert) {
                    try {
                        $revert();
                    } catch (\Throwable $failure) {
                        $operation->line('!! Putting it back failed too: '.$failure->getMessage());
                    }
                }

                $operation->fail($e->getMessage());

                throw $e;
            }
        });
    }

    /**
     * The domains in a SERVER_NAME, e.g. "https://jane.example, www.jane.example:443, php:80".
     *
     * @return list<Domain>
     */
    public static function domainsOf(string $serverName): array
    {
        $domains = [];

        foreach (explode(',', $serverName) as $address) {
            $host = (string) preg_replace(['#^[a-z]+://#i', '#:\d+\z#'], '', trim($address));

            try {
                $domain = new Domain($host);
            } catch (\InvalidArgumentException) {
                // E.g. "php" (the container's own name) or ":80"
                continue;
            }

            $domains[$domain->value] = $domain;
        }

        return array_values($domains);
    }

    /**
     * @param \Closure(string): void $say
     *
     * @return string|null The final backup's path, or null when skipped
     */
    public function remove(SiteName $name, bool $backup, string $channel, string $requestedBy, \Closure $say): ?string
    {
        return $this->lock->run($name, function () use ($name, $backup, $channel, $requestedBy, $say): ?string {
            $site = $this->registry->get($name);

            if (!$site instanceof Site || Site::REMOVED === $site->status) {
                throw new \DomainException(\sprintf('There\'s no site named "%s".', $name));
            }

            $operation = $this->operations->start('site.remove', $name, ['backup' => $backup], $channel, $requestedBy);
            $directory = $this->paths->site($name);
            // A site whose add was cut short may have no folder yet: nothing of it to back up or stop.
            $project = is_dir($directory) ? $this->project($name, $site->domains, $site->project, $site->databaseMode) : null;
            $step = static function (string $what) use ($operation, $say): void {
                $operation->line('== '.$what);
                $say($what);
            };

            try {
                $file = null;

                if ($backup && $project instanceof SiteProject) {
                    $step('Backing it up');
                    $file = $this->backup($name, $project, 'final');
                    $operation->line('Backed up to '.$file);
                } elseif ($backup) {
                    $operation->line('Its folder isn\'t there: nothing to back up');
                }

                $step('Closing it to visitors');
                // Not running any more first, so the agent's rounds don't open it again.
                $this->registry->setStatus($name, Site::REMOVING);

                foreach ($site->domains as $domain) {
                    $this->frontDoor->revoke($domain);
                }

                $step('Stopping it and removing its containers and volumes');

                if ($project instanceof SiteProject && is_file($directory.'/'.SiteFiles::ENV_FILE)) {
                    $this->compose->down($project, volumes: true, output: $operation->output(...));
                }

                if (Site::SHARED_DATABASE === $site->databaseMode) {
                    $step('Removing its database');
                    $this->databases->drop($name);
                }

                $step('Archiving its folder');
                $this->archive($name, $directory);
                // Its deploy key can read its repository: gone with it (remove it from the repository too).
                $this->keys->remove($name);
                $this->repositories->forget($name);
                $this->registry->setStatus($name, Site::REMOVED);
                $operation->succeed();

                return $file;
            } catch (\Throwable $e) {
                $operation->fail($e->getMessage());

                throw $e;
            }
        });
    }

    /**
     * At the agent's start: sites an add or a restore as a new site left being created (the agent
     * stopped while it ran) are removed. They never ran; their names and domains are free again.
     *
     * @return list<string> The sites removed
     */
    public function removeUnfinished(): array
    {
        $removed = [];

        foreach ($this->registry->all() as $site) {
            if (Site::CREATING === $site->status) {
                $this->remove($site->name, false, 'agent', 'agent', static fn (): null => null);
                $removed[] = $site->name->value;
            }
        }

        return $removed;
    }

    /**
     * The site's Compose project, and what the policy allows it: its domains on the front door's
     * network, and the server's PostgreSQL network only when its database is there.
     *
     * @param list<Domain> $domains
     */
    public function project(SiteName $name, array $domains, string $project, string $databaseMode): SiteProject
    {
        return SiteProject::of($this->paths, $name, $domains, $project, $databaseMode);
    }

    public function projectOf(Site $site): SiteProject
    {
        return $this->project($site->name, $site->domains, $site->project, $site->databaseMode);
    }

    /**
     * Installs the site: its first admin invited by email when asked and the site can, otherwise with
     * a password that's shown once and never logged.
     *
     * @return string|null The admin's password, when one was made
     */
    private function install(SiteProject $project, SiteRequest $request, Operation $operation): ?string
    {
        // Sites that keep the server contract (Quire 0.1.1 and later) can invite.
        $about = $this->compose->exec($project, self::PHP, ['bin/console', 'quire:about', '--format=json'], timeout: 120);
        $report = $about->successful() ? json_decode($about->output, true) : null;
        $contract = \is_array($report) ? ($report['contract'] ?? null) : null;
        $invite = $request->invite && \is_int($contract) && $contract >= 1 && '' !== $this->mailerDsn;

        if ($request->invite && !$invite) {
            $operation->line('This site can\'t invite (it needs Quire 0.1.1 or later, and an email server): a password is made instead.');
        }

        $result = $this->compose->exec($project, self::PHP, [
            'bin/console', 'quire:install', '--no-interaction',
            '--admin-email='.$request->adminEmail,
            '--title='.$request->title,
            ...($invite ? ['--invite'] : []),
        ], timeout: 300);

        // The output has the password: scrubbed before it's logged.
        $password = 1 === preg_match('/The admin\'s password is: (\S+)/', $result->output, $match) ? $match[1] : null;

        if (null !== $password) {
            $operation->secret($password);
        }

        $operation->output($result->output.$result->errorOutput);
        $result->orFail('Installing the site');

        return $password;
    }

    /**
     * A backup of the site with quire:backup (Quire's data and files), to
     * /srv/quire/backups/<site>/<kind>-<time>.tar, and the rest of its database beside it
     * (SiteDatabaseDumps), readable by root only.
     */
    public function backup(SiteName $name, SiteProject $project, string $kind): string
    {
        $directory = $this->paths->backups($name);
        $this->filesystem->mkdir($directory, 0o700);
        $file = $directory.'/'.$kind.'-'.new \DateTimeImmutable()->format('Ymd-His').'.tar';
        // A name of its own each time: one an interrupted backup left (quire:backup won't overwrite a
        // file) never stops the next.
        $inside = '/tmp/quire-'.$kind.'-backup-'.bin2hex(random_bytes(6)).'.tar';

        try {
            $this->compose->exec($project, self::PHP, ['bin/console', 'quire:backup', $inside, '--no-interaction'], timeout: 3600)->orFail('Backing up the site');
            $this->compose->copyFrom($project, self::PHP, $inside, $file);
        } finally {
            $this->compose->exec($project, self::PHP, ['rm', '-f', $inside], timeout: 60);
        }

        chmod($file, 0o600);
        Durable::sync($file);
        $this->dumps->dump($project, $this->registry->get($name)->databaseMode ?? Site::SHARED_DATABASE, SiteDatabaseDumps::of($file));

        return $file;
    }

    /**
     * After quire:restore: the rest of the site's database, from the dump beside the backup (backups
     * made before Quire Server 0.3.1 have none).
     *
     * @param (\Closure(string): void)|null $output
     */
    public function restoreDatabase(SiteName $name, SiteProject $project, string $backup, ?\Closure $output = null): void
    {
        $dump = SiteDatabaseDumps::of($backup);

        if (is_file($dump)) {
            $this->dumps->restore($project, $this->registry->get($name)->databaseMode ?? Site::SHARED_DATABASE, $dump, $output);
        }
    }

    /**
     * Moves the site's folder out of the way, kept: data is never deleted without a backup.
     */
    /**
     * The versions the site runs, recorded in its folder: the composer.lock its image was built with,
     * copied out of it when the folder has none (a skeleton has none: its first build resolves the
     * versions). From then on, builds install exactly these, and updates change them.
     */
    public function pinComposerLock(SiteName $name, SiteProject $project): void
    {
        $lock = $this->paths->site($name).'/composer.lock';

        if (is_file($lock)) {
            return;
        }

        $this->compose->copyFrom($project, self::PHP, '/app/composer.lock', $lock.'.tmp');
        chmod($lock.'.tmp', 0o644);
        $this->filesystem->rename($lock.'.tmp', $lock);
    }

    private function archive(SiteName $name, string $directory): void
    {
        if (!file_exists($directory)) {
            return;
        }

        $target = $this->paths->removed($name, new \DateTimeImmutable());
        $this->filesystem->mkdir(\dirname($target), 0o700);
        $this->filesystem->rename($directory, $target);
    }
}
