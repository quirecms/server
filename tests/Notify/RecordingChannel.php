<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Notify;

use Quire\Server\Notify\Channel;

/**
 * A channel that keeps what it was given, or fails with the reason given.
 */
final class RecordingChannel implements Channel
{
    /** @var list<array{subject: string, text: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function __construct(
        private readonly string $name,
        public ?string $failure = null,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function send(string $subject, string $text, array $payload): void
    {
        if (null !== $this->failure) {
            throw new \RuntimeException($this->failure);
        }

        $this->sent[] = ['subject' => $subject, 'text' => $text, 'payload' => $payload];
    }
}
