<?php

declare(strict_types=1);

namespace Quire\Server\Release;

use Quire\Server\Process\ProcessRunner;

/**
 * Releases of a GitHub repository: which is the latest, their files, and whether a file was signed by
 * the repository's release workflow for exactly that release's tag (Sigstore, keyless, through
 * GitHub's identity provider). Only HTTPS; downloads are limited in size.
 */
final readonly class GitHubReleases
{
    private const string ISSUER = 'https://token.actions.githubusercontent.com';

    public function __construct(
        private ProcessRunner $runner,
    ) {
    }

    /**
     * The latest release's version (pre-releases aside, as GitHub's "latest" is), or null when there's none.
     *
     * @throws ReleaseVerificationFailed When GitHub can't be reached, or names a tag that isn't a version
     */
    public function latest(string $repository): ?Version
    {
        $result = $this->runner->run([...$this->curl(), '-o', '/dev/null', '-w', '%{url_effective}', \sprintf('https://github.com/%s/releases/latest', $repository)], timeout: 60);

        if (!$result->successful()) {
            throw new ReleaseVerificationFailed(\sprintf('GitHub couldn\'t be reached for %s\'s latest release: %s', $repository, trim($result->errorOutput)));
        }

        if (1 !== preg_match('#^https://github\.com/'.preg_quote($repository, '#').'/releases/tag/v([^/?\#]+)\z#', trim($result->output), $match)) {
            return null;
        }

        return Version::tryFrom($match[1]) ?? throw new ReleaseVerificationFailed(\sprintf('%s\'s latest release\'s tag isn\'t a version.', $repository));
    }

    /**
     * Downloads one of a release's files into a folder, readable by others (cosign may run as a user
     * of its own; the files are public).
     *
     * @throws ReleaseVerificationFailed When it can't be downloaded
     */
    public function download(string $repository, Version $version, string $file, string $work): string
    {
        $path = $work.'/'.$file;
        $result = $this->runner->run([...$this->curl(), '--retry', '3', '-o', $path, \sprintf('https://github.com/%s/releases/download/v%s/%s', $repository, $version, $file)], timeout: 300);

        if (!$result->successful()) {
            throw new ReleaseVerificationFailed(\sprintf('%s of %s %s couldn\'t be downloaded (is there such a release?).', $file, $repository, $version));
        }

        chmod($path, 0o644);

        return $path;
    }

    /**
     * Whether a file was signed by the repository's release workflow, run for this version's tag (its
     * Sigstore bundle beside it, as "<file>.sigstore.json").
     */
    public function signed(string $repository, Version $version, string $file, string $work): bool
    {
        return $this->runner->run(
            ['cosign', 'verify-blob', '--bundle', $file.'.sigstore.json', '--certificate-identity', self::identity($repository, $version), '--certificate-oidc-issuer', self::ISSUER, $file],
            // Sigstore's trusted roots are cached in its home.
            ['HOME' => $work],
            timeout: 120,
        )->successful();
    }

    /**
     * Who must have signed a release's files: the repository's release workflow, run for its tag.
     */
    public static function identity(string $repository, Version $version): string
    {
        return \sprintf('https://github.com/%s/.github/workflows/release.yaml@refs/tags/v%s', $repository, $version);
    }

    /**
     * @return list<string>
     */
    private function curl(): array
    {
        return ['curl', '-fsSL', '--proto', '=https', '--tlsv1.2', '--max-filesize', '104857600'];
    }
}
