<?php

declare(strict_types=1);

namespace Quire\Server\Release;

use Quire\Server\Process\ProcessRunner;

/**
 * Quire Server's releases on GitHub, got and checked as install.sh checks them: release.env must be
 * signed (Sigstore, keyless) by this repository's release workflow for exactly that version's tag; it
 * names the archive by checksum and the image by digest, so nothing else needs a signature of its own.
 * (The image is signed too, by the same workflow; its digest in a signed file is the same guarantee.).
 *
 * Nothing here is configurable: who may sign a release is part of the code that checks it.
 */
final readonly class Releases
{
    public const string REPOSITORY = 'quirecms/server';

    public function __construct(
        private GitHubReleases $github,
        private ProcessRunner $runner,
    ) {
    }

    /**
     * The latest release's version (pre-releases aside, as GitHub's "latest" is), or null when there's none.
     *
     * @throws ReleaseVerificationFailed When GitHub can't be reached
     */
    public function latest(): ?Version
    {
        return $this->github->latest(self::REPOSITORY);
    }

    /**
     * Gets a release into a folder of its own (made here, empty), checks it and extracts its files.
     *
     * @param \Closure(string): void $say
     *
     * @throws ReleaseVerificationFailed When anything doesn't check out: then nothing of it may be used
     */
    public function fetch(Version $version, string $work, \Closure $say): Release
    {
        if (!mkdir($work.'/files', 0o755, true) && !is_dir($work.'/files')) {
            throw new ReleaseVerificationFailed(\sprintf('The folder %s can\'t be made.', $work));
        }

        // cosign may run as a user of its own: it reads these (public) files.
        chmod($work, 0o755);
        $releaseEnv = $this->github->download(self::REPOSITORY, $version, 'release.env', $work);
        $this->github->download(self::REPOSITORY, $version, 'release.env.sigstore.json', $work);

        if (!$this->github->signed(self::REPOSITORY, $version, $releaseEnv, $work)) {
            throw new ReleaseVerificationFailed(\sprintf('The signature of Quire Server %s didn\'t check out (it must be signed by %s): not using it.', $version, self::identity($version)));
        }

        $say('Its signature checks out (Sigstore: signed by its release workflow, for v'.$version.')');
        $values = $this->values((string) file_get_contents($releaseEnv));

        if (($values['VERSION'] ?? null) !== $version->value) {
            throw new ReleaseVerificationFailed('The release names another version: not using it.');
        }

        $archive = $values['ARCHIVE'] ?? '';
        $checksum = $values['ARCHIVE_SHA256'] ?? '';
        $image = $values['IMAGE'] ?? '';

        if (1 !== preg_match('/^quire-server-[0-9A-Za-z.-]+\.tar\.gz\z/', $archive)) {
            throw new ReleaseVerificationFailed('The release names no archive: not using it.');
        }

        if (1 !== preg_match('/^[0-9a-f]{64}\z/', $checksum)) {
            throw new ReleaseVerificationFailed('The release names no checksum: not using it.');
        }

        if (1 !== preg_match('#^ghcr\.io/'.preg_quote(self::REPOSITORY, '#').'@sha256:[0-9a-f]{64}\z#', $image)) {
            throw new ReleaseVerificationFailed('The release names no image: not using it.');
        }

        $file = $this->github->download(self::REPOSITORY, $version, $archive, $work);

        if (!hash_equals($checksum, (string) hash_file('sha256', $file))) {
            throw new ReleaseVerificationFailed(\sprintf('%s isn\'t the release\'s (its checksum differs): not using it.', $archive));
        }

        $this->extract($file, $work.'/files');

        return new Release($version, $image, $work.'/files');
    }

    /**
     * Who must have signed a release: this repository's release workflow, run for its tag.
     */
    public static function identity(Version $version): string
    {
        return GitHubReleases::identity(self::REPOSITORY, $version);
    }

    /**
     * Extracts the archive's files (under its one top folder), refusing any path that would land
     * outside, though its checksum is signed.
     */
    private function extract(string $archive, string $into): void
    {
        $listed = $this->runner->run(['tar', '-tzf', $archive], timeout: 120)->orFail('Reading the release\'s archive');

        foreach (explode("\n", trim($listed->output)) as $path) {
            if (str_starts_with($path, '/') || \in_array('..', explode('/', $path), true)) {
                throw new ReleaseVerificationFailed(\sprintf('The release\'s archive has a path outside it ("%s"): not using it.', $path));
            }
        }

        $this->runner->run(['tar', '-xzf', $archive, '-C', $into, '--strip-components=1', '--no-same-owner'], timeout: 300)->orFail('Extracting the release');

        if (!is_file($into.'/compose.yaml') || !is_file($into.'/bin/quire-server')) {
            throw new ReleaseVerificationFailed('The release\'s archive isn\'t Quire Server\'s (no compose.yaml, bin/quire-server): not using it.');
        }
    }

    /**
     * @return array<string, string> release.env's values (KEY=value lines; the first of each wins)
     */
    private function values(string $content): array
    {
        $values = [];

        foreach (explode("\n", $content) as $line) {
            if (1 === preg_match('/^([A-Z][A-Z0-9_]*)=(.*)\z/', rtrim($line, "\r"), $match) && !isset($values[$match[1]])) {
                $values[$match[1]] = $match[2];
            }
        }

        return $values;
    }
}
