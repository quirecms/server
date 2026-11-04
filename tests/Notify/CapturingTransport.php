<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Notify;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A mailer that keeps what it was given, or fails.
 */
final class CapturingTransport implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function __construct(
        private readonly ?\Throwable $failure = null,
    ) {
    }

    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        $this->sent[] = $message;

        return null;
    }

    #[\Override]
    public function __toString(): string
    {
        return 'capturing://';
    }
}
