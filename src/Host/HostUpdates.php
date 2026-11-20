<?php

declare(strict_types=1);

namespace Quire\Server\Host;

use Quire\Server\Notify\Problem;
use Quire\Server\Process\ProcessRunner;

/**
 * Whether the machine under the sites gets its fixes (ARC-0061): a restart pending for a newer kernel,
 * Docker's packages below a floor known to be fixed or outside the majors this release is tested on,
 * and the machine's automatic updates having stopped. Sites share the machine's kernel and Docker, so
 * these are what holds them apart (SECURITY.md).
 *
 * Read from what host-setup found at the server's start (gate/host-updates.sh, in host_facts), the
 * host's /usr/lib/modules and /var/lib/apt/periodic (read-only), the running kernel (a container's is
 * the host's), and Docker's own versions through the trusted socket.
 */
final readonly class HostUpdates
{
    /**
     * The oldest release of each branch without a known escape or high-severity flaw, per component,
     * from the advisories as of this release (2026-11-18). A branch older than every one listed has no
     * fixes any more. Raised by a release when an advisory comes (later from the signed feed, ADR-0003).
     *
     * - runc: CVE-2025-31133, CVE-2025-52565, CVE-2025-52881 (container escapes, 2025-11-05): 1.2.8,
     *   1.3.3, 1.4.0.
     * - containerd: GHSA-pg57-6jwg-q645 (CVE-2026-53493, 2026-09-24), which also covers the 2026 ones
     *   before it (GHSA-rp3h-jf77-q9p4, GHSA-fqw6-gf59-qr4w, GHSA-rgh6-rfwx-v388): 2.4.1, 2.3.6, 2.2.9,
     *   2.0.13; 2.1 is at its end with no fix.
     * - Docker Engine: the oldest the server runs on (install.sh's docker_min).
     */
    public const array FLOORS = [
        'runc' => ['1.2' => '1.2.8', '1.3' => '1.3.3', '1.4' => '1.4.0'],
        'containerd' => ['2.0' => '2.0.13', '2.2' => '2.2.9', '2.3' => '2.3.6', '2.4' => '2.4.1'],
        'Engine' => ['29.0' => '29.0.0'],
    ];

    /** The automatic updates run daily: after this long without a run, they've stopped */
    public const int UPDATES_LATE = 3 * 86400;

    /** The Docker major this release is tested on (gate/host-updates.sh's list) */
    public const string TESTED_DOCKER_MAJOR = '29';

    /** The release whose floors these are, as the status line says */
    public const string FLOORS_OF = '0.6.0';

    /**
     * @param \Closure(): string|null             $kernel the running kernel's release (uname -r)
     * @param \Closure(): \DateTimeImmutable|null $now
     */
    public function __construct(
        private ProcessRunner $runner,
        private string $dockerHost,
        private string $facts = '/run/quire-host/host-updates',
        private string $kernels = '/run/quire-host-kernels',
        private string $apt = '/run/quire-host-apt',
        private ?\Closure $kernel = null,
        private ?\Closure $now = null,
    ) {
    }

    /**
     * @return list<Problem>
     */
    public function problems(): array
    {
        $facts = $this->facts();

        return [...$this->kernelProblem($facts), ...$this->dockerProblems($facts), ...$this->updatesProblem($facts)];
    }

    /**
     * What host-setup found: "key=value" lines, or nothing when it didn't run (an older release).
     *
     * @return array<string, string>
     */
    public function facts(): array
    {
        $lines = @file($this->facts, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $facts = [];

        foreach (false === $lines ? [] : $lines as $line) {
            if (1 === preg_match('/^([a-z_]+)=(.*)\z/', $line, $match)) {
                $facts[$match[1]] = $match[2];
            }
        }

        return $facts;
    }

    /** A newer kernel of the running one's kind is installed than the one that runs: it waits for a restart */
    public function pendingKernel(): ?string
    {
        $running = $this->kernel instanceof \Closure ? ($this->kernel)() : php_uname('r');
        $flavor = $this->flavor($running);
        $installed = @scandir($this->kernels);
        $newest = null;

        foreach (false === $installed ? [] : $installed as $name) {
            if (str_starts_with($name, '.') || $this->flavor($name) !== $flavor || !is_dir($this->kernels.'/'.$name)) {
                continue;
            }
            if (strnatcmp($name, $running) > 0 && (null === $newest || strnatcmp($name, $newest) > 0)) {
                $newest = $name;
            }
        }

        return $newest;
    }

    /**
     * Docker's own versions, as its daemon says: Engine, containerd and runc.
     *
     * @return array<string, string>
     */
    public function dockerVersions(): array
    {
        $result = $this->runner->run(['docker', 'version', '--format', '{{json .Server.Components}}'], ['DOCKER_HOST' => $this->dockerHost], timeout: 30);
        $components = $result->successful() ? json_decode(trim($result->output), true) : null;
        $versions = [];

        foreach (\is_array($components) ? $components : [] as $component) {
            if (\is_array($component) && \is_string($component['Name'] ?? null) && \is_string($component['Version'] ?? null)) {
                $versions[$component['Name']] = ltrim($component['Version'], 'v');
            }
        }

        return $versions;
    }

    /**
     * Whether a version is below its branch's floor: the floor it's below, or null. A branch newer than
     * every one listed is above them all; one older has no fixes any more.
     */
    public static function belowFloor(string $component, string $version): ?string
    {
        $floors = self::floorsOf($component);

        if ([] === $floors || 1 !== preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $match)) {
            return null;
        }
        $branch = $match[1].'.'.$match[2];
        $plain = $match[1].'.'.$match[2].'.'.$match[3];

        if (isset($floors[$branch])) {
            return version_compare($plain, $floors[$branch], '<') ? $floors[$branch] : null;
        }
        // Each component's branches are listed oldest first (a test checks it)
        $oldest = array_key_first($floors);
        $newest = array_key_last($floors);

        if (version_compare($branch, $oldest, '<')) {
            return $floors[$oldest];
        }

        return version_compare($branch, $newest, '>') ? null : $floors[$newest];
    }

    /**
     * @param array<string, string> $facts
     *
     * @return list<Problem>
     */
    private function kernelProblem(array $facts): array
    {
        $pending = $this->pendingKernel();

        if (null === $pending) {
            return [];
        }
        $running = $this->kernel instanceof \Closure ? ($this->kernel)() : php_uname('r');

        // The machine restarts by itself after its updates already (whoever's file says so): only when
        $advice = 'on' === ($facts['effective_reboot'] ?? null)
            ? 'A kernel\'s fixes apply only once the machine restarts. It restarts by itself after its next automatic updates (%s); to have them sooner, restart it now ("sudo reboot"; sites are back by themselves within a few minutes).'
            : 'A kernel\'s fixes apply only once the machine restarts. Restart it ("sudo reboot"; sites are back by themselves within a few minutes), or let it restart by itself after its updates: "quire-server settings:set QUIRE_SERVER_REBOOT=on".';

        return [new Problem('host.kernel', \sprintf('The machine waits for a restart: kernel %s is installed, %s runs.', $pending, $running), \sprintf($advice, self::updateTime($facts)))];
    }

    /**
     * @param array<string, string> $facts
     *
     * @return list<Problem>
     */
    private function dockerProblems(array $facts): array
    {
        $problems = [];

        if ('untested-major' === ($facts['docker_updates'] ?? null)) {
            $problems[] = new Problem('host.docker-major', \sprintf('Docker\'s automatic updates are off: %s is installed, and this release of Quire Server is tested on Docker %s.', $facts['docker_untested'] ?? 'another major', self::TESTED_DOCKER_MAJOR), 'Nothing of Docker\'s is moved, held or downgraded. A release of Quire Server tested on that major turns its updates on again ("quire-server update").');
        }

        $versions = $this->dockerVersions();
        $below = [];

        foreach (['runc', 'containerd', 'Engine'] as $component) {
            $floor = isset($versions[$component]) ? self::belowFloor($component, $versions[$component]) : null;

            if (null !== $floor) {
                $below[] = \sprintf('%s %s (at least %s)', 'Engine' === $component ? 'Docker' : $component, $versions[$component], $floor);
            }
        }

        if ([] !== $below) {
            $problems[] = new Problem('host.docker', \sprintf('Docker is older than its fixes: %s, by the floor of Quire Server %s.', implode(', ', $below), self::FLOORS_OF), 'Sites are held apart by Docker and the kernel they share. Update Docker\'s packages ("sudo apt-get install --only-upgrade docker-ce docker-ce-cli containerd.io"), or check that the machine\'s automatic updates run.');
        }

        return $problems;
    }

    /**
     * @param array<string, string> $facts
     *
     * @return list<Problem>
     */
    private function updatesProblem(array $facts): array
    {
        if (\in_array($facts['docker_updates'] ?? null, ['off', 'os-updates-off', 'no-apt', null], true)) {
            return [];
        }
        $stamp = @filemtime($this->apt.'/upgrade-stamp');

        if (false === $stamp) {
            return [];
        }
        $now = $this->now instanceof \Closure ? ($this->now)() : new \DateTimeImmutable();

        if ($now->getTimestamp() - $stamp < self::UPDATES_LATE) {
            return [];
        }

        return [new Problem('host.updates', \sprintf('The machine\'s automatic updates haven\'t run since %s.', new \DateTimeImmutable('@'.$stamp)->format('Y-m-d H:i').' UTC'), 'Security fixes for the machine, Docker included, wait until they run again: "systemctl status apt-daily-upgrade.timer" and /var/log/unattended-upgrades/ say why.')];
    }

    /**
     * When the machine's automatic updates run, as an owner reads it: "daily from 03:30 UTC" (systemd's
     * timer, at the server's last start), "daily from 03:30 UTC, once the machine restarts" (server.env's
     * hour is written, but systemd couldn't be told yet), or "at the machine's own time".
     *
     * @param array<string, string> $facts
     */
    public static function updateTime(array $facts): string
    {
        $hour = $facts['update_hour'] ?? '';

        if (1 === preg_match('/^\d{1,2}\z/', $hour) && 'no' === ($facts['update_hour_applied'] ?? null)) {
            return \sprintf('daily from %02d:30 UTC, once the machine restarts', (int) $hour);
        }

        // "*-*-* 03:30:00 UTC": systemd's own reading of the timer
        if (1 === preg_match('/^\*-\*-\* (\d{2}):(\d{2}):\d{2}(?: (UTC))?\z/', $facts['effective_update_time'] ?? '', $match)) {
            return \sprintf('daily from %s:%s %s', $match[1], $match[2], $match[3] ?? '(the machine\'s time zone)');
        }

        return 'at the machine\'s own time';
    }

    /**
     * @return array<string, string> Branch => floor, oldest branch first
     */
    private static function floorsOf(string $component): array
    {
        return self::FLOORS[$component] ?? [];
    }

    /** A kernel's kind ("generic", "aws"…): what follows its version */
    private function flavor(string $release): string
    {
        return 1 === preg_match('/^[\d.]+-\d+-(.+)\z/', $release, $match) ? $match[1] : '';
    }
}
