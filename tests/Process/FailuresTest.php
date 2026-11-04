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
