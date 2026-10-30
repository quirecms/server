<?php

declare(strict_types=1);

namespace Quire\Server\Process;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Runs a program in a container of its own, for programs that act on what a site gives them, e.g.
 * Composer on a site's composer.json, which names the addresses it fetches from. In the agent, any
 * address it reaches is one of the agent's networks: the docker-proxy's, which is root on the host,
 * or the database's. The container has the agent's image (its programs), but none of its networks:
 * one of its own, with a way to the internet only. It runs as nobody, with no capabilities and a
 * read-only root, and sees only a copy of the files in the folder it's given, never the site's folder.
 * What it leaves there (regular files only) is copied back after it ends.
 */
final readonly class ContainerRunner implements ProcessRunner
{
    public const string NETWORK = 'quire-sandbox';
    public const string LABEL = 'com.quirecms.server.sandbox';

    /** Who runs the program: nobody */
    private const int USER = 65534;
    private const string FOLDER = '/work';

    /** Larger files from the container aren't copied back */
    private const int MAX_FILE = 64 * 1024 * 1024;

    private Filesystem $filesystem;

    public function __construct(
        private ProcessRunner $runner,
        private string $dockerHost,
        private string $image,
        /** A folder at the same path in the agent and on the host, for the containers' copies */
        private string $work,
        /** Who runs the program (tests run it as themselves) */
        private int $user = self::USER,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * @param array<string, string|false> $env Not secrets: they're arguments of "docker run"
     */
    #[\Override]
    public function run(array $command, array $env = [], ?string $directory = null, float $timeout = 600, ?\Closure $output = null, ?string $input = null, ?string $inputFile = null): ProcessResult
    {
        if (null === $directory || !is_dir($directory)) {
            throw new \InvalidArgumentException('A program in a container runs in a folder of files it is given.');
        }

        $this->network();
        $name = 'quire-sandbox-'.bin2hex(random_bytes(6));
        $copy = $this->work.'/'.$name;
        $this->filesystem->mkdir($copy, 0o700);

        try {
            $this->copy($directory, $copy);
            $this->filesystem->chown($copy, $this->user, true);

            $variables = [];

            foreach ($env as $variable => $value) {
                if (false !== $value) {
                    $variables[] = '--env';
                    $variables[] = $variable.'='.$value;
                }
            }

            $result = $this->runner->run(
                [
                    'docker', 'run', '--rm', '--name', $name, '--label', self::LABEL.'=1',
                    '--network', self::NETWORK,
                    '--user', $this->user.':'.$this->user,
                    '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
                    '--read-only', '--tmpfs', '/tmp:size=512m,mode=1777',
                    '--memory', '1g', '--pids-limit', '256', '--cpus', '1',
                    '--volume', $copy.':'.self::FOLDER,
                    '--workdir', self::FOLDER,
                    '--env', 'HOME=/tmp',
                    ...$variables,
                    ...(null === $input && null === $inputFile ? [] : ['--interactive']),
                    $this->image,
                    // The folder's path, as the program sees it
                    ...array_map(static fn (string $argument): string => str_replace($directory, self::FOLDER, $argument), $command),
                ],
                ['DOCKER_HOST' => $this->dockerHost],
                null,
                $timeout,
                $output,
                $input,
                $inputFile,
            );

            // Only once it has surely ended: nothing changes the files while they're copied.
            $this->remove($name);
            $this->copy($copy, $directory);

            return new ProcessResult($command, $result->exitCode, $result->output, $result->errorOutput);
        } finally {
            $this->remove($name);
            $this->filesystem->remove($copy);
        }
    }

    /**
     * A program that ran out of time is still running: "--rm" doesn't stop it.
     */
    private function remove(string $name): void
    {
        $this->runner->run(['docker', 'rm', '--force', $name], ['DOCKER_HOST' => $this->dockerHost], timeout: 60);
    }

    /**
     * The containers' network: Docker's default kind, with a way out, and nothing else on it.
     */
    private function network(): void
    {
        if ($this->runner->run(['docker', 'network', 'inspect', self::NETWORK], ['DOCKER_HOST' => $this->dockerHost], timeout: 60)->successful()) {
            return;
        }

        $created = $this->runner->run(['docker', 'network', 'create', '--label', self::LABEL.'=1', self::NETWORK], ['DOCKER_HOST' => $this->dockerHost], timeout: 60);

        // Another run may have just created it.
        if (!$created->successful() && !str_contains($created->errorOutput, 'already exists')) {
            $created->orFail('Creating the network for programs in containers');
        }
    }

    /**
     * The regular files directly in one folder, into another: not links (from the container, one could
     * point at any file of the agent's), not folders.
     */
    private function copy(string $from, string $to): void
    {
        $entries = scandir($from);

        foreach (false === $entries ? [] : $entries as $entry) {
            $file = $from.'/'.$entry;

            if (is_link($file) || !is_file($file) || filesize($file) > self::MAX_FILE) {
                continue;
            }

            $this->filesystem->copy($file, $to.'/'.$entry, true);
        }
    }
}
