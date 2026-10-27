<?php

declare(strict_types=1);

namespace Quire\Server\Operation;

use Quire\Server\Database\SiteDatabases;
use Quire\Server\Docker\Compose;
use Quire\Server\Docker\PolicyContext;
use Quire\Server\Docker\SiteProject;
use Quire\Server\Docker\SkeletonInstaller;
use Quire\Server\FrontDoor\DomainDirectory;
use Quire\Server\Registry\SiteLock;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
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
                'quire_version' => $request->quireVersion,
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
                    throw new \DomainException(\sprintf('The folder "%s" exists already: move it away first.', $directory));
                }

                $step('Recording the site');
                $this->registry->add($request->name, $request->domains, Site::SHARED_DATABASE, $request->name->value);
                // Undone, it's removed: its name and domains are free to try again; its history stays.
                $undo[] = fn () => $this->registry->setStatus($request->name, Site::REMOVED);

                $step(\sprintf('Getting Quire %s', $request->quireVersion));
                $undo[] = fn () => $this->archive($request->name, $directory);
                $this->skeleton->create($directory, $request->quireVersion, $operation->output(...));

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
                $project = $this->project($request->name, $request->domains);

                $step('Checking and building it (a few minutes)');
                $undo[] = fn () => $this->compose->down($project, volumes: true);
                $this->compose->build($project, $operation->output(...));

                $step('Starting it');
                $this->compose->up($project, $operation->output(...));

                $step('Installing it');
                $adminPassword = $this->install($project, $request, $operation);

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
        });
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
            $project = $this->project($name, $site->domains);
            $step = static function (string $what) use ($operation, $say): void {
                $operation->line('== '.$what);
                $say($what);
            };

            try {
                $file = null;

                if ($backup) {
                    $step('Backing it up');
                    $file = $this->finalBackup($name, $project);
                    $operation->line('Backed up to '.$file);
                }

                $step('Closing it to visitors');
                // Not running any more first, so the agent's rounds don't open it again.
                $this->registry->setStatus($name, Site::REMOVING);

                foreach ($site->domains as $domain) {
                    $this->frontDoor->revoke($domain);
                }

                $step('Stopping it and removing its containers and volumes');

                if (is_file($directory.'/'.SiteFiles::ENV_FILE)) {
                    $this->compose->down($project, volumes: true, output: $operation->output(...));
                }

                if (Site::SHARED_DATABASE === $site->databaseMode) {
                    $step('Removing its database');
                    $this->databases->drop($name);
                }

                $step('Archiving its folder');
                $this->archive($name, $directory);
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
     * @param list<Domain> $domains
     */
    public function project(SiteName $name, array $domains): SiteProject
    {
        $directory = $this->paths->site($name);

        return new SiteProject($directory, $directory.'/'.SiteFiles::ENV_FILE, new PolicyContext($name->value, $directory, [
            'quire-edge' => [self::PHP => array_map(static fn (Domain $domain): string => $domain->value, $domains)],
            'quire-data' => [self::PHP => [], 'worker' => []],
        ]));
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

    private function finalBackup(SiteName $name, SiteProject $project): string
    {
        $directory = $this->paths->backups($name);
        $this->filesystem->mkdir($directory, 0o700);
        $file = $directory.'/final-'.new \DateTimeImmutable()->format('Ymd-His').'.tar';
        $inside = '/tmp/quire-final-backup.tar';

        $this->compose->exec($project, self::PHP, ['bin/console', 'quire:backup', $inside, '--no-interaction'], timeout: 3600)->orFail('Backing up the site');
        $this->compose->copyFrom($project, self::PHP, $inside, $file);
        $this->compose->exec($project, self::PHP, ['rm', '-f', $inside], timeout: 60);
        chmod($file, 0o600);

        return $file;
    }

    /**
     * Moves the site's folder out of the way, kept: data is never deleted without a backup.
     */
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
