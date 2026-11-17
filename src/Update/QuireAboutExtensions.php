<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Docker\Compose;
use Quire\Server\Operation\SiteManager;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;

/**
 * A site's extensions and theme, as its own "quire:about --format=json" says: each extension with its
 * package and status ("enabled", "disabled", "broken": enabled, but it failed to start), and the id of
 * its active theme.
 */
final readonly class QuireAboutExtensions implements SiteExtensions
{
    private const string PHP = 'php';

    public function __construct(
        private SiteRegistry $registry,
        private SiteManager $sites,
        private Compose $compose,
    ) {
    }

    #[\Override]
    public function of(SiteName $name): array
    {
        $site = $this->registry->get($name);

        if (!$site instanceof Site || Site::RUNNING !== $site->status) {
            throw new \RuntimeException(\sprintf('"%s" isn\'t running, so which extensions it runs can\'t be told.', $name));
        }

        $result = $this->compose->exec($this->sites->projectOf($site), self::PHP, ['bin/console', 'quire:about', '--format=json'], timeout: 120);

        if (!$result->successful() || \strlen($result->output) > SiteContract::MAX_REPORT) {
            throw new \RuntimeException(\sprintf('"%s" didn\'t say which extensions it runs (quire:about): %s', $name, $result->successful() ? 'its report is larger than any report is' : trim(mb_substr($result->errorOutput.$result->output, -300))));
        }

        $about = json_decode($result->output, true, 64);

        if (!\is_array($about) || !\is_array($about['extensions'] ?? null)) {
            throw new \RuntimeException(\sprintf('"%s"\'s quire:about report names no extensions (Quire before 0.1.1?), so which it runs can\'t be told.', $name));
        }

        $extensions = [];

        foreach ($about['extensions'] as $extension) {
            if (\is_array($extension) && \is_string($extension['id'] ?? null) && \is_string($extension['package'] ?? null) && \is_string($extension['status'] ?? null)) {
                $extensions[] = ['id' => $extension['id'], 'package' => $extension['package'], 'status' => $extension['status']];
            }
        }

        return ['extensions' => $extensions, 'theme' => \is_string($about['theme'] ?? null) ? $about['theme'] : null];
    }
}
