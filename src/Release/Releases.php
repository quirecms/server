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
    private const string ISSUER = 'https://token.actions.githubusercontent.com';

    public function __construct(
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
        $result = $this->runner->run([...$this->curl(), '-o', '/dev/null', '-w', '%{url_effective}', \sprintf('https://github.com/%s/releases/latest', self::REPOSITORY)], timeout: 60);

        if (!$result->successful()) {
            throw new ReleaseVerificationFailed(\sprintf('GitHub couldn\'t be reached for the latest release: %s', trim($result->errorOutput)));
        }

        if (1 !== preg_match('#^https://github\.com/'.preg_quote(self::REPOSITORY, '#').'/releases/tag/v([^/?\#]+)\z#', trim($result->output), $match)) {
            return null;
        }

        return Version::tryFrom($match[1]) ?? throw new ReleaseVerificationFailed('The latest release\'s tag isn\'t a version.');
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
        $this->download($version, 'release.env', $work);
        $this->download($version, 'release.env.sigstore.json', $work);

        $identity = self::identity($version);
        $verified = $this->runner->run(
            ['cosign', 'verify-blob', '--bundle', $work.'/release.env.sigstore.json', '--certificate-identity', $identity, '--certificate-oidc-issuer', self::ISSUER, $work.'/release.env'],
            // Sigstore's trusted roots are cached in its home.
            ['HOME' => $work],
            timeout: 120,
        );

        if (!$verified->successful()) {
            throw new ReleaseVerificationFailed(\sprintf('The signature of Quire Server %s didn\'t check out (it must be signed by %s): not using it.', $version, $identity));
        }

        $say('Its signature checks out (Sigstore: signed by its release workflow, for v'.$version.')');
        $values = $this->values((string) file_get_contents($work.'/release.env'));

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

        $this->download($version, $archive, $work);

        if (!hash_equals($checksum, (string) hash_file('sha256', $work.'/'.$archive))) {
            throw new ReleaseVerificationFailed(\sprintf('%s isn\'t the release\'s (its checksum differs): not using it.', $archive));
        }

        $this->extract($work.'/'.$archive, $work.'/files');

        return new Release($version, $image, $work.'/files');
    }

    /**
     * Who must have signed a release: this repository's release workflow, run for its tag.
     */
    public static function identity(Version $version): string
    {
        return \sprintf('https://github.com/%s/.github/workflows/release.yaml@refs/tags/v%s', self::REPOSITORY, $version);
    }

    private function download(Version $version, string $file, string $work): void
    {
        $result = $this->runner->run([...$this->curl(), '--retry', '3', '-o', $work.'/'.$file, \sprintf('https://github.com/%s/releases/download/v%s/%s', self::REPOSITORY, $version, $file)], timeout: 300);

        if (!$result->successful()) {
            throw new ReleaseVerificationFailed(\sprintf('%s of Quire Server %s couldn\'t be downloaded (is there such a release?).', $file, $version));
        }

        chmod($work.'/'.$file, 0o644);
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
     * @return list<string>
     */
    private function curl(): array
    {
        return ['curl', '-fsSL', '--proto', '=https', '--tlsv1.2', '--max-filesize', '104857600'];
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
