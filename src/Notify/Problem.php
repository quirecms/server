<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

/**
 * Something that needs the owner (D-35), as the agent finds it: a key that names it for as long as it
 * lasts ("operation:site.update:jane", "certificate:jane.example", "disk"), what's wrong and what to do,
 * in plain sentences without secrets: they're sent by email and to a webhook.
 */
final readonly class Problem
{
    /** A key: lowercase words, dots, dashes, colons and a site's or a domain's name */
    private const string KEY = '/^[a-z][a-z0-9.:_-]{0,199}\z/';

    public function __construct(
        public string $key,
        public string $summary,
        public string $advice,
        public ?string $site = null,
        /** The operation whose failure it is, when it's one ("quire-server operations <id>") */
        public ?string $operation = null,
        public ?\DateTimeImmutable $since = null,
    ) {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a problem\'s key.', $key));
        }
    }
}
