<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Process\ProcessRunner;

/**
 * Quire's runtime images (ghcr.io/quirecms/runtime), which sites from Quire's skeleton build on: their
 * Dockerfile names them in build arguments (RUNTIME_BUILDER, RUNTIME). Before a site is built, each
 * one it names is checked: signed (Sigstore, keyless) by quirecms/framework's runtime workflow, from
 * main. The build then gets it by the digest that was checked, so what's built on is exactly what was
 * checked, whatever the tag points to by then. A runtime that doesn't check out stops the build.
 *
 * Other base images (a site that changed its Dockerfile) are the site's choice: built on as they are,
 * and said so in the operation's log.
 */
final readonly class RuntimeImages
{
    public const string REPOSITORY = 'ghcr.io/quirecms/runtime';
    private const string IDENTITY = 'https://github.com/quirecms/framework/.github/workflows/runtime.yaml@refs/heads/main';
    private const string ISSUER = 'https://token.actions.githubusercontent.com';
    private const array ARGUMENTS = ['RUNTIME_BUILDER', 'RUNTIME'];

    public function __construct(
        private ProcessRunner $runner,
    ) {
    }

    /**
     * The build arguments that pin the runtime images a site's Dockerfile names, each checked.
     *
     * @param \Closure(string): void $say
     *
     * @return array<string, string> Argument => image by digest
     *
     * @throws RuntimeImageNotTrusted When one doesn't check out
     */
    public function pinned(string $directory, \Closure $say): array
    {
        $dockerfile = @file_get_contents($directory.'/Dockerfile');

        if (false === $dockerfile) {
            return [];
        }

        $arguments = [];
        // ARG lines before the first FROM: the images FROM may name
        // (Instructions are case-insensitive.)
        $head = preg_split('/^[ \t]*FROM[ \t]/mi', $dockerfile, 2)[0] ?? '';
        preg_match_all('/^[ \t]*ARG[ \t]+([A-Z_]+)=(\S+)[ \t]*$/mi', $head, $matches, \PREG_SET_ORDER);

        foreach ($matches as [, $name, $image]) {
            if (!\in_array($name, self::ARGUMENTS, true)) {
                continue;
            }

            if (!str_starts_with($image, self::REPOSITORY.':') && !str_starts_with($image, self::REPOSITORY.'@')) {
                $say(\sprintf('%s is %s, not Quire\'s runtime: built on as it is.', $name, $image));

                continue;
            }

            if (1 !== preg_match('#^'.preg_quote(self::REPOSITORY, '#').'(?::[0-9A-Za-z][0-9A-Za-z._-]{0,127})?(?:@sha256:[0-9a-f]{64})?\z#', $image)) {
                throw new RuntimeImageNotTrusted(\sprintf('%s names "%s", which isn\'t an image of Quire\'s runtime.', $name, $image));
            }

            $digest = $this->verify($image);
            $pinned = (str_contains($image, '@') ? strstr($image, '@', true) : $image).'@'.$digest;
            $say(\sprintf('Quire\'s runtime %s checks out (Sigstore: signed by its workflow): %s', $image, $digest));
            $arguments[$name] = $pinned;
        }

        return $arguments;
    }

    /**
     * @return string The digest whose signature checked out
     */
    private function verify(string $image): string
    {
        $result = $this->runner->run(
            ['cosign', 'verify', '--certificate-identity', self::IDENTITY, '--certificate-oidc-issuer', self::ISSUER, '--output', 'json', $image],
            // Sigstore's trusted roots are cached in its home.
            ['HOME' => sys_get_temp_dir()],
            timeout: 120,
        );

        if (!$result->successful()) {
            throw new RuntimeImageNotTrusted(\sprintf('Quire\'s runtime %s didn\'t check out (it must be signed by %s): not building on it.', $image, self::IDENTITY));
        }

        $digests = [];

        foreach (explode("\n", trim($result->output)) as $line) {
            $verified = json_decode($line, true);

            foreach (\is_array($verified) ? $verified : [] as $signature) {
                $critical = \is_array($signature) && \is_array($signature['critical'] ?? null) ? $signature['critical'] : [];
                $digest = \is_array($critical['image'] ?? null) ? ($critical['image']['docker-manifest-digest'] ?? null) : null;

                if (\is_string($digest)) {
                    $digests[$digest] = true;
                }
            }
        }

        $digest = array_key_first($digests);

        if (1 !== \count($digests) || !\is_string($digest) || 1 !== preg_match('/^sha256:[0-9a-f]{64}\z/', $digest)) {
            throw new RuntimeImageNotTrusted(\sprintf('Checking Quire\'s runtime %s didn\'t say which image it checked: not building on it.', $image));
        }

        if (str_contains($image, '@') && !str_ends_with($image, '@'.$digest)) {
            throw new RuntimeImageNotTrusted(\sprintf('Quire\'s runtime %s isn\'t the image that was checked: not building on it.', $image));
        }

        return $digest;
    }
}
