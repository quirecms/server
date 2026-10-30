<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

use Quire\Server\Site\EnvFile;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A site's Compose files as they are on disk, checked before Compose reads them. Part of what they say
 * is gone from the configuration Compose prints (the policy's input): "include" and "extends" with a
 * "file" are resolved by then, and the files they name read, as Compose may read any file the agent
 * can, another site's prod.env among them (an "include" with an "env_file" fills in "${…}" from it).
 * So a site's configuration is its own files only: those its prod.env names, in its folder.
 */
final readonly class ComposeFiles
{
    /**
     * @return list<string> What isn't allowed, if anything
     */
    public function violations(SiteProject $project): array
    {
        $files = EnvFile::read($project->envFile)->get('COMPOSE_FILE') ?? 'compose.yaml';
        $violations = [];

        foreach (explode(':', $files) as $file) {
            $path = $project->directory.'/'.$file;

            if ('' === $file || str_starts_with($file, '/') || \in_array('..', explode('/', $file), true) || !$project->policy->contains($path)) {
                $violations[] = \sprintf('"%s" (COMPOSE_FILE) isn\'t a file of the site\'s folder.', $file);

                continue;
            }

            if (!is_file($path)) {
                continue; // Compose says it's missing.
            }

            try {
                $config = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
            } catch (ParseException $e) {
                $violations[] = \sprintf('%s isn\'t YAML the server can read: %s', $file, $e->getMessage());

                continue;
            }

            if (\is_array($config) && \array_key_exists('include', $config)) {
                $violations[] = \sprintf('%s: "include" reads other files; a site\'s configuration is its own files (COMPOSE_FILE in its prod.env).', $file);
            }

            foreach (\is_array($config) && \is_array($config['services'] ?? null) ? $config['services'] : [] as $name => $service) {
                if (\is_array($service) && \is_array($service['extends'] ?? null) && \array_key_exists('file', $service['extends'])) {
                    $violations[] = \sprintf('%s: service "%s" extends one of another file; only one of the same file can be extended.', $file, $name);
                }
            }
        }

        return $violations;
    }
}
