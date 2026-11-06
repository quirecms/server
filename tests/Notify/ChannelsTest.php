<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Notify;

use PHPUnit\Framework\TestCase;
use Quire\Server\Notify\EmailChannel;
use Quire\Server\Notify\NotifySettings;
use Quire\Server\Notify\WebhookChannel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final class ChannelsTest extends TestCase
{
    private const string SECRET = 'a-secret-of-at-least-32-characters-long';

    public function testTheWebhooksSignatureIsGitHubs(): void
    {
        // GitHub's own example (docs: "Validating webhook deliveries")
        self::assertSame('sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17', WebhookChannel::signature('Hello, World!', 'It\'s a Secret to Everybody'));
    }

    public function testAWebhookGetsTheTextAndTheDataSigned(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('ok', ['http_code' => 200]);
        });

        new WebhookChannel($this->settings(), $client)->send('Quire Server: The disk is almost full', 'On your Quire Server…', ['event' => 'problems', 'problems' => [['key' => 'disk']]]);

        [$method, $url, $options] = $requests[0];
        self::assertSame(['POST', 'https://hooks.example/services/T000/B000/XXXX'], [$method, $url]);
        self::assertIsString($options['body']);
        $body = json_decode($options['body'], true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame("Quire Server: The disk is almost full\n\nOn your Quire Server…", $body['text'], 'Slack and Discord show "text" as it is.');
        self::assertSame('disk', $this->at($body, 'problems', 0, 'key'));
        $headers = $options['headers'];
        self::assertIsArray($headers);
        self::assertContains('X-Quire-Signature-256: '.WebhookChannel::signature($options['body'], self::SECRET), $headers);
        self::assertSame(0, $options['max_redirects'], 'Where it goes is the owner\'s setting, not the receiver\'s.');
    }

    public function testAWebhookNeverSpeaksTlsOlderThan12(): void
    {
        $sent = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = $options;

            return new MockResponse('ok', ['http_code' => 200]);
        });

        // A client whose defaults would allow TLS 1.0
        new WebhookChannel($this->settings(), $client->withOptions(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT]))->send('Subject', 'Text', []);

        self::assertSame(\STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $sent[0]['crypto_method']);
    }

    public function testAWebhookThatDoesntTakeItFailsWithoutItsSecretAddress(): void
    {
        $channel = new WebhookChannel($this->settings(), new MockHttpClient([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://elsewhere.example/']])]));

        try {
            $channel->send('s', 't', []);
            self::fail('A redirect isn\'t a delivery.');
        } catch (\RuntimeException $e) {
            self::assertSame('The webhook to hooks.example answered 302.', $e->getMessage());
        }

        self::assertSame('webhook to hooks.example', $channel->name(), 'Slack\'s and Discord\'s paths are secrets: never shown.');
    }

    public function testAnEmailFromTheOwnerToTheOwnerMarkedAutomatic(): void
    {
        $transport = new CapturingTransport();
        $settings = new NotifySettings(mailerDsn: 'smtp://jane:p4ssw0rd-secret@mail.example:587', acmeEmail: 'jane@example.com');
        new EmailChannel($settings, static fn (): TransportInterface => $transport)->send('Quire Server: a test', "This is a test.\n", []);

        $email = $transport->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(['jane@example.com', 'jane@example.com', 'Quire Server: a test', "This is a test.\n"], [$email->getFrom()[0]->getAddress(), $email->getTo()[0]->getAddress(), $email->getSubject(), $email->getTextBody()]);
        self::assertSame('auto-generated', $email->getHeaders()->get('Auto-Submitted')?->getBodyAsString());

        $from = new CapturingTransport();
        new EmailChannel(new NotifySettings('owner@example.com', 'server@example.com', 'smtp://mail.example'), static fn (): TransportInterface => $from)->send('s', 't', []);
        self::assertInstanceOf(Email::class, $from->sent[0]);
        self::assertSame(['server@example.com', 'owner@example.com'], [$from->sent[0]->getFrom()[0]->getAddress(), $from->sent[0]->getTo()[0]->getAddress()]);
    }

    public function testAMailerThatFailsSaysWhyWithoutItsPassword(): void
    {
        $transport = new CapturingTransport(new TransportException('Failed to authenticate on SMTP server with username "jane" using p4ssw0rd-secret'));
        $channel = new EmailChannel(new NotifySettings('jane@example.com', mailerDsn: 'smtp://jane:p4ssw0rd-secret@mail.example:587'), static fn (): TransportInterface => $transport);

        try {
            $channel->send('s', 't', []);
            self::fail('It failed.');
        } catch (\RuntimeException $e) {
            self::assertSame('The email to jane@example.com couldn\'t be sent: Failed to authenticate on SMTP server with username "jane" using ***', $e->getMessage());
        }
    }

    public function testSettingsAreChecked(): void
    {
        self::assertSame([], new NotifySettings()->problems(), 'None set: nothing wrong (nobody is told: "status" says so).');
        self::assertSame([
            'QUIRE_SERVER_NOTIFY_EMAIL isn\'t an email address.',
            'Problems aren\'t emailed: QUIRE_SERVER_MAILER_DSN, the mailer to send them with, isn\'t set.',
            'QUIRE_SERVER_NOTIFY_WEBHOOK is an https:// address (with no user or password in it).',
            'QUIRE_SERVER_NAME is a short name for this server, in plain characters.',
        ], new NotifySettings('jane', webhook: 'https://user:pass@hooks.example/x', secret: self::SECRET, name: "two\nlines")->problems());
        self::assertSame([], $this->settings()->problems());
        self::assertSame([self::SECRET, 'https://hooks.example/services/T000/B000/XXXX', 'pw'], new NotifySettings(mailerDsn: 'smtp://u:pw@mail.example', webhook: 'https://hooks.example/services/T000/B000/XXXX', secret: self::SECRET)->secrets());
    }

    /**
     * A value deep in decoded data, failing the test when it isn't there.
     */
    private function at(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data);
            $data = $data[$key];
        }

        return $data;
    }

    private function settings(): NotifySettings
    {
        return new NotifySettings(webhook: 'https://hooks.example/services/T000/B000/XXXX', secret: self::SECRET);
    }
}
