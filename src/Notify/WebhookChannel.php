<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A JSON POST to the owner's webhook. Its body carries "text", so Slack's and Discord's incoming
 * webhooks take it as it is; each request is signed as GitHub signs its webhooks:
 * X-Quire-Signature-256: sha256=<HMAC-SHA256 of the body, with QUIRE_SERVER_NOTIFY_SECRET>. The body's
 * "sent_at" lets a receiver refuse an old request replayed.
 */
final readonly class WebhookChannel implements Channel
{
    public const string SIGNATURE = 'X-Quire-Signature-256';

    public function __construct(
        private NotifySettings $settings,
        private HttpClientInterface $client,
    ) {
    }

    /**
     * The signature header's value for a body.
     */
    public static function signature(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    #[\Override]
    public function name(): string
    {
        return 'webhook to '.$this->settings->webhookHost();
    }

    #[\Override]
    public function send(string $subject, string $text, array $payload): void
    {
        $body = json_encode(['text' => $subject."\n\n".$text, ...$payload], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->client->request('POST', $this->settings->webhook, [
                'body' => $body,
                'headers' => ['Content-Type' => 'application/json', self::SIGNATURE => self::signature($body, $this->settings->secret)],
                // Where it goes is the owner's setting, not the receiver's
                'max_redirects' => 0,
                // TLS 1.2 or newer, whatever the client's defaults say
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                'timeout' => 10,
                'max_duration' => 20,
            ]);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('The webhook to %s couldn\'t be reached: %s', $this->settings->webhookHost(), str_replace($this->settings->secrets(), '***', $e->getMessage())), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(\sprintf('The webhook to %s answered %d.', $this->settings->webhookHost(), $status));
        }
    }
}
