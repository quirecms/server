<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

/**
 * A way to tell the owner (email, a webhook).
 */
interface Channel
{
    /** What it's called to the owner, e.g. "email to jane@example.com" (no secret in it) */
    public function name(): string;

    /**
     * @param array<string, mixed> $payload The same as data, for machines (webhooks)
     *
     * @throws \RuntimeException When it couldn't be sent, with why
     */
    public function send(string $subject, string $text, array $payload): void;
}
