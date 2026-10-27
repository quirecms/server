<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Process\ProcessRunner;

/**
 * Sites' images, through the docker-proxy: which one a name points to, and pointing a name at another
 * one (a site's previous image, kept to roll back to).
 */
final readonly class Images
{
    /** A local image name with an optional tag, e.g. "jane-php-prod" or "jane-php-prod:previous" */
    private const string NAME = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?\z/';
    private const string ID = '/^sha256:[0-9a-f]{64}\z/';

    public function __construct(
        private ProcessRunner $runner,
        private string $dockerHost,
    ) {
    }

    /**
     * The ID of the image a name points to, or null when there's none.
     */
    public function id(string $name): ?string
    {
        $result = $this->docker(['image', 'inspect', '--format', '{{.Id}}', $this->name($name)]);
        $id = trim($result->output);

        return $result->successful() && 1 === preg_match(self::ID, $id) ? $id : null;
    }

    /**
     * Points a name at an image (by its ID, so it's exactly that one).
     */
    public function tag(string $id, string $name): void
    {
        if (1 !== preg_match(self::ID, $id)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t an image ID.', $id));
        }

        $this->docker(['image', 'tag', $id, $this->name($name)])->orFail('Naming the image');
    }

    /**
     * Removes a name (the image itself goes once no name or container uses it).
     */
    public function untag(string $name): void
    {
        $this->docker(['image', 'rm', '--no-prune', $this->name($name)]);
    }

    private function name(string $name): string
    {
        return 1 === preg_match(self::NAME, $name) ? $name : throw new \InvalidArgumentException(\sprintf('"%s" isn\'t an image name.', $name));
    }

    /**
     * @param list<string> $arguments
     */
    private function docker(array $arguments): \Quire\Server\Process\ProcessResult
    {
        return $this->runner->run(['docker', ...$arguments], ['DOCKER_HOST' => $this->dockerHost], timeout: 120);
    }
}
