<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Process\ProcessRunner;

/**
 * A site's images, on its own socket of the Docker gate: which one a name points to, and pointing a
 * name at another one (a site's previous image, kept to roll back to). The gate lets a site name
 * images only in its own namespace ("quire-sites/<project>/").
 */
final readonly class Images
{
    /** A local name, its path's parts as Docker's (e.g. "quire-sites/jane/php-prod"), and a tag */
    private const string NAME = '#^[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?\z#';
    private const string ID = '/^sha256:[0-9a-f]{64}\z/';

    public function __construct(
        private ProcessRunner $runner,
        private Gate $gate,
    ) {
    }

    /**
     * The ID of the image a name points to, or null when there's none.
     */
    public function id(SiteProject $project, string $name): ?string
    {
        $result = $this->docker($project, ['image', 'inspect', '--format', '{{.Id}}', $this->name($name)]);
        $id = trim($result->output);

        return $result->successful() && 1 === preg_match(self::ID, $id) ? $id : null;
    }

    /**
     * The ID of the image one of the site's containers runs, or null when there's no such container.
     */
    public function ofContainer(SiteProject $project, string $container): ?string
    {
        $result = $this->docker($project, ['container', 'inspect', '--format', '{{.Image}}', $container]);
        $id = trim($result->output);

        return $result->successful() && 1 === preg_match(self::ID, $id) ? $id : null;
    }

    /**
     * Points a name at an image (by its ID, so it's exactly that one).
     */
    public function tag(SiteProject $project, string $id, string $name): void
    {
        if (1 !== preg_match(self::ID, $id)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t an image ID.', $id));
        }

        $this->docker($project, ['image', 'tag', $id, $this->name($name)])->orFail('Naming the image');
    }

    /**
     * Removes a name (the image itself goes once no name or container uses it).
     */
    public function untag(SiteProject $project, string $name): void
    {
        $this->docker($project, ['image', 'rm', '--no-prune', $this->name($name)]);
    }

    private function name(string $name): string
    {
        return 1 === preg_match(self::NAME, $name) ? $name : throw new \InvalidArgumentException(\sprintf('"%s" isn\'t an image name.', $name));
    }

    /**
     * @param list<string> $arguments
     */
    private function docker(SiteProject $project, array $arguments): \Quire\Server\Process\ProcessResult
    {
        return $this->runner->run(['docker', ...$arguments], $this->gate->site($project), timeout: 120);
    }
}
