<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Release\Feed;
use Quire\Server\Release\FeedNotTrusted;
use Quire\Server\Release\QuireFeed;

/**
 * That the code a site is built with is the code Quire released (D-38), as its signed feed says:
 *
 *   - each quire/* package of its lock names the commit the feed gives for its version, and comes
 *     from GitHub's repository of that package (Composer doesn't check a download against the
 *     commit it names: a planted address would pass);
 *   - a new site's skeleton (and an update's) has exactly the files the feed gives for its version.
 *
 * A mismatch stops the build. A version the feed doesn't vouch for (a branch, a fork, a release
 * before feeds named commits) is built, and said. A feed that doesn't check out stops it too; one
 * that can't be got (an outage) is said, and the build goes on, as updates do.
 */
final readonly class CodeCheck
{
    /** Archives leave these out (the skeleton's .gitattributes): neither side has them */
    private const array NOT_ARCHIVED = ['.github/'];

    public function __construct(
        private QuireFeed $feed,
    ) {
    }

    /**
     * @return list<string> What it couldn't vouch for, to say
     *
     * @throws CodeNotReleased When a package isn't the code Quire released
     */
    public function lock(string $file): array
    {
        $lock = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        $packages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($lock) && \is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (\is_array($package) && \is_string($package['name'] ?? null) && str_starts_with($package['name'], 'quire/')) {
                    $packages[] = $package;
                }
            }
        }

        if ([] === $packages) {
            return [];
        }

        [$feed, $notes] = $this->read();

        if (!$feed instanceof Feed) {
            return $notes;
        }

        foreach ($packages as $package) {
            $name = $package['name'];
            $version = \is_string($package['version'] ?? null) ? ltrim($package['version'], 'v') : '';
            $release = $feed->release($version);
            $expected = $release['packages'][$name] ?? null;

            if (null === $expected) {
                $notes[] = \sprintf('%s %s isn\'t vouched for by Quire\'s release feed (%s): built as it is.', $name, '' === $version ? '?' : $version, null === $release ? 'not one of its releases' : 'a release from before the feed named commits');

                continue;
            }

            $repository = substr($name, \strlen('quire/'));
            $dist = \is_array($package['dist'] ?? null) ? $package['dist'] : [];
            $source = \is_array($package['source'] ?? null) ? $package['source'] : [];
            $wrong = match (true) {
                $expected !== ($dist['reference'] ?? null) => 'its download names commit '.$this->text($dist['reference'] ?? null),
                \sprintf('https://api.github.com/repos/quirecms/%s/zipball/%s', $repository, $expected) !== ($dist['url'] ?? null) => 'its download comes from '.$this->text($dist['url'] ?? null),
                [] !== $source && ($expected !== ($source['reference'] ?? null) || \sprintf('https://github.com/quirecms/%s.git', $repository) !== ($source['url'] ?? null)) => 'its source is '.$this->text($source['url'] ?? null).' at '.$this->text($source['reference'] ?? null),
                default => null,
            };

            if (null !== $wrong) {
                throw new CodeNotReleased(\sprintf('%s %s isn\'t the code Quire released: %s, where Quire\'s release feed says commit %s of quirecms/%s. A package\'s tag may have been moved, or other code served for it: nothing was built.', $name, $version, $wrong, $expected, $repository));
            }
        }

        return $notes;
    }

    /**
     * @return list<string> What it couldn't vouch for, to say
     *
     * @throws CodeNotReleased When the skeleton isn't the one Quire released for that version
     */
    public function skeleton(string $directory, string $version): array
    {
        [$feed, $notes] = $this->read();

        if (!$feed instanceof Feed) {
            return $notes;
        }

        $expected = $feed->release(ltrim($version, 'v'))['skeleton'] ?? null;

        if (null === $expected) {
            return [...$notes, \sprintf('Quire\'s skeleton %s isn\'t vouched for by its release feed: used as it is.', $version)];
        }

        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO), \RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $path = substr($file->getPathname(), \strlen(rtrim($directory, '/')) + 1);
            // A link is no file of the skeleton's: its target would be read
            $found[$path] = $file->isLink() ? 'link' : (string) hash_file('sha256', $file->getPathname());
        }

        $archived = static fn (string $path): bool => [] === array_filter(self::NOT_ARCHIVED, static fn (string $prefix): bool => str_starts_with($path, $prefix));
        $found = array_filter($found, $archived, \ARRAY_FILTER_USE_KEY);
        $expected = array_filter($expected, $archived, \ARRAY_FILTER_USE_KEY);
        $differ = array_keys(array_diff_assoc($found, $expected) + array_diff_key($expected, $found));
        sort($differ);

        if ([] !== $differ) {
            throw new CodeNotReleased(\sprintf('Quire\'s skeleton %s as it was got isn\'t the one Quire released: %s %s. Nothing was built.', $version, implode(', ', \array_slice($differ, 0, 5)).(\count($differ) > 5 ? ' and '.(\count($differ) - 5).' more' : ''), 1 === \count($differ) ? 'differs' : 'differ'));
        }

        return $notes;
    }

    /**
     * @return array{?Feed, list<string>}
     */
    private function read(): array
    {
        try {
            $feed = $this->feed->read();
        } catch (FeedNotTrusted $e) {
            throw new CodeNotReleased($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [null, ['Quire\'s release feed couldn\'t be read ('.$e->getMessage().'): the code isn\'t checked against it.']];
        }

        return $feed instanceof Feed ? [$feed, []] : [null, ['Quire has no release feed yet: the code isn\'t checked against it.']];
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : 'none';
    }
}
