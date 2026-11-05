<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Process;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Process\Failures;
use Quire\Server\Process\ProcessFailed;
use Quire\Server\Process\ProcessResult;
use Quire\Server\Process\ProcessTimedOut;

/**
 * Failures as the audit met them (its recorded outputs, fixtures/), said plainly with what to do
 * (M-20: OWNER-10, the 23 messages graded in its §2a).
 */
final class FailuresTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function recorded(): iterable
    {
        yield 'disk full during a deploy (#5, #6)' => ['fail-disk-full-deploy.txt', 'the disk is full (no space left on device)', 'Make room on it'];
        yield 'the bucket refusing its keys (#8, #10)' => ['fail-restic-wrong-secret-key.txt', 'the bucket refused the server\'s keys (access denied)', 'QUIRE_SERVER_BACKUP_SECRET_KEY'];
        yield 'the backups\' wrong password (#12)' => ['fail-restic-wrong-password-restore.txt', 'the backups\' password (QUIRE_SERVER_BACKUP_PASSWORD in server.env) doesn\'t open the repository', 'the one shown when backups were set up'];
        yield 'a deploy key removed (#15)' => ['fail-deploy-key-removed.txt', 'the repository refused the site\'s deploy key', 'quire-server site:key <site>'];
        yield 'the Git host unreachable (#16)' => ['fail-git-host-unreachable.txt', '172.21.0.5 couldn\'t be reached from this server', 'network and DNS'];
        yield 'Packagist unreachable (#17)' => ['fail-offline-site-add.txt', 'repo.packagist.org couldn\'t be reached from this server', 'network and DNS'];
        yield 'GitHub\'s 504 on one download, mid-build (droplet scale test, 2026-11-05)' => ['fail-build-download-504.txt', 'api.github.com answered HTTP 504 while symfony/process was downloaded: a problem on its side or on the way, usually brief', 'Try again in a few minutes'];
        yield 'a package that doesn\'t exist (#13)' => ['fail-update-missing-package-oplog.txt', 'Composer couldn\'t work out the versions to install: Root composer.json requires quire/does-not-exist, it could not be found in any version, there may be a typo in the package name.', 'composer.json'];
    }

    #[DataProvider('recorded')]
    public function testARecordedFailureIsSaidPlainly(string $fixture, string $why, string $next): void
    {
        // (The log's excerpt was saved with grep -n: its line numbers aren't the log's)
        $explained = Failures::explain((string) preg_replace('/^\d+:/m', '', (string) file_get_contents(__DIR__.'/fixtures/'.$fixture)));

        self::assertNotNull($explained, $fixture);
        self::assertSame($why, $explained['why']);
        self::assertStringContainsString($next, $explained['next']);
    }

    public function testAFailedBuildStepSaysWhatItSaidLast(): void
    {
        // BuildKit's plain progress, as Compose prints it without a terminal (the step of #14)
        $output = implode("\n", [
            '#19 [php 7/9] RUN composer install --no-cache --prefer-dist --no-dev',
            '#19 DONE 41.2s',
            '#20 [php 8/9] RUN mkdir -p var/cache var/log',
            '#20 0.211 + composer dump-env prod',
            '#20 0.512 asset build failed: out of memory',
            '#20 ERROR: process "/bin/bash -euxo pipefail -c mkdir -p var/cache var/log\ncomposer dump-env prod\necho \"asset build failed: out of memory\" >&2; exit 3" did not complete successfully: exit code: 3',
        ]);

        self::assertSame('a step of its build failed (exit code 3): asset build failed: out of memory', Failures::explain($output)['why'] ?? null);
        // The rendered message the audit recorded: the step's command, when its output isn't there
        self::assertStringStartsWith('a step of its build failed (exit code 3): mkdir -p var/cache', Failures::explain((string) file_get_contents(__DIR__.'/fixtures/fail-update-build-step.txt'))['why'] ?? '');
    }

    public function testAFailedStepSaysItsErrorNotTheUsageAfterIt(): void
    {
        // Composer and Symfony's console print the command's usage after their error box
        $output = implode("\n", [
            '#17 6.314 In PackageRepository.php line 88:',
            '#17 6.314   The lock file is not up to date with the latest changes in composer.json.',
            '#17 6.315 install [--prefer-source] [--prefer-dist] [--prefer-install PREFER-INSTALL] [--dry-run] [--dev]',
            '#17 6.315 ',
            '#17 ERROR: process "/bin/bash -euxo pipefail -c composer install --no-dev" did not complete successfully: exit code: 4',
        ]);

        self::assertSame('a step of its build failed (exit code 4): The lock file is not up to date with the latest changes in composer.json.', Failures::explain($output)['why'] ?? null);
    }

    public function testOnlyWhatPassesByItselfIsTriedAgain(): void
    {
        self::assertTrue(Failures::transient((string) file_get_contents(__DIR__.'/fixtures/fail-build-download-504.txt')), 'A host\'s 504.');
        self::assertTrue(Failures::transient('curl error 28 while downloading https://repo.packagist.org/p2/quire/core.json: Operation timed out after 10001 milliseconds'));
        self::assertTrue(Failures::transient('failed to fetch: unexpected status from GET request to https://ghcr.io/v2/quirecms/runtime/blobs/sha256:00: 503 Service Unavailable'));
        self::assertTrue(Failures::transient('read tcp 10.72.0.5:4312->140.82.121.6:443: read: connection reset by peer'));

        foreach (['fail-disk-full-deploy.txt', 'fail-offline-site-add.txt', 'fail-update-missing-package-oplog.txt', 'fail-update-build-step.txt', 'fail-deploy-key-removed.txt'] as $fixture) {
            self::assertFalse(Failures::transient((string) file_get_contents(__DIR__.'/fixtures/'.$fixture)), $fixture.': a setting or the site\'s code, said at once.');
        }

        self::assertFalse(Failures::transient('The "https://api.github.com/repos/x/y/zipball/1" file could not be downloaded (HTTP/2 404 )'), 'Not there: not there either in 30 s.');
    }

    public function testWhatItDoesntKnowIsNotGuessed(): void
    {
        self::assertNull(Failures::explain("Error: something else entirely\n"));
    }

    public function testAProcessThatFailedSaysWhatItMeansAndKeepsWhatItSaid(): void
    {
        $result = new ProcessResult(['restic', 'snapshots'], 12, '', "{\"message_type\":\"exit_error\",\"code\":12,\"message\":\"Fatal: wrong password or no key found\"}\n");
        $failed = new ProcessFailed('Listing the backups', $result);

        self::assertStringStartsWith('Listing the backups failed: the backups\' password (QUIRE_SERVER_BACKUP_PASSWORD in server.env) doesn\'t open the repository. Put back', $failed->getMessage());
        self::assertStringNotContainsString('message_type', $failed->getMessage(), 'restic\'s JSON is for the log.');
        self::assertStringContainsString('wrong password or no key found', $failed->detail());

        $unknown = new ProcessFailed('Doing it', new ProcessResult(['tool'], 2, '', "line one\nsomething odd\n"));
        self::assertSame("Doing it failed (exit code 2):\nline one\nsomething odd", $unknown->getMessage(), 'What it doesn\'t know: the tool\'s own words.');
    }

    public function testATimeoutIsSaidAsOne(): void
    {
        $build = new ProcessTimedOut(['docker', 'compose', '--project-directory', '/srv/quire/sites/jane', 'build', '--pull', '--build-arg', 'RUNTIME=ghcr.io/quirecms/runtime@sha256:x'], 1800);

        self::assertStringStartsWith('Building the site took longer than 30 minutes, its time limit, and was stopped', $build->getMessage());
        self::assertStringNotContainsString('--project-directory', $build->getMessage(), 'Not its command line (#23).');
        self::assertStringStartsWith('Composer took longer than 90 seconds', new ProcessTimedOut(['/usr/bin/composer', 'update'], 90)->getMessage());
    }
}
