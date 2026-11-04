<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Notify;

use Quire\Server\Notify\Channel;
use Quire\Server\Notify\Notifier;
use Quire\Server\Notify\NotifySettings;
use Quire\Server\Notify\Problem;
use Quire\Server\Notify\Problems;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class NotifierTest extends RegistryTestCase
{
    private Problems $problems;
    private int $now;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->problems = new Problems($this->connection, $this->schema);
        $this->now = 1_800_000_000;
    }

    public function testEachProblemIsToldWhenItStartsAndWhenItsOver(): void
    {
        $email = new RecordingChannel('email');
        $notifier = $this->notifier([$email]);

        $this->problems->sync([new Problem('operation:site.update:jane', 'The automatic update of jane failed.', 'See "quire-server operations 1234".', 'jane', 'a1b2c3d4-0000-4000-8000-000000000001')]);
        self::assertSame(['Told: Quire Server Hetzner 1: The automatic update of jane failed'], $notifier->round());
        self::assertSame('Quire Server Hetzner 1: The automatic update of jane failed', $email->sent[0]['subject']);
        self::assertStringContainsString("New:\n- The automatic update of jane failed.\n  See \"quire-server operations 1234\".\n  Since ", $email->sent[0]['text']);
        self::assertStringContainsString('(operation:site.update:jane)', $email->sent[0]['text'], 'Its key, to dismiss it.');
        self::assertSame(['problems', 'Hetzner 1', '1.2.3'], [$email->sent[0]['payload']['event'], $email->sent[0]['payload']['server'], $email->sent[0]['payload']['version']]);
        self::assertSame('started', $this->at($email->sent[0]['payload'], 'problems', 0, 'event'));

        $this->problems->sync([new Problem('operation:site.update:jane', 'The automatic update of jane failed.', 'See it.', 'jane', 'a1b2c3d4-0000-4000-8000-000000000001')]);
        self::assertSame([], $notifier->round(), 'The next night it\'s still there: nothing new to tell.');

        $this->problems->sync([]);
        $notifier->round();
        self::assertSame('Quire Server Hetzner 1: Fixed: The automatic update of jane failed', $this->at($email->sent, 1, 'subject'));
        self::assertSame('fixed', $this->at($email->sent, 1, 'payload', 'problems', 0, 'event'));
        self::assertCount(2, $email->sent);
        self::assertSame([], $notifier->round(), 'Told: forgotten.');
    }

    public function testSeveralAtOnceAreOneMessage(): void
    {
        $email = new RecordingChannel('email');
        $this->problems->sync([new Problem('disk', 'The disk is almost full.', 'Make room.'), new Problem('backups.late', 'Backups haven\'t run.', 'Look.')]);

        $this->notifier([$email])->round();

        self::assertCount(1, $email->sent);
        self::assertSame('Quire Server Hetzner 1: 2 new problems', $email->sent[0]['subject']);
    }

    public function testAChannelThatFailsIsAProblemToldOnTheOther(): void
    {
        $email = new RecordingChannel('email', 'The email to jane@example.com couldn\'t be sent: Connection refused.');
        $webhook = new RecordingChannel('webhook');
        $notifier = $this->notifier([$email, $webhook]);
        $this->problems->sync([new Problem('disk', 'The disk is almost full.', 'Make room.')]);

        $notifier->round();
        self::assertCount(1, $webhook->sent, 'The webhook still hears of it.');
        self::assertNotContains('disk', $this->started(), 'It was told, on the channel that works.');
        $own = array_values(array_filter($this->problems->open(), static fn (array $stored): bool => Problems::NOTIFY === $stored['problem']->key));
        self::assertStringContainsString('Connection refused', $own[0]['problem']->summary);

        $this->now += 60;
        self::assertSame([], $notifier->round(), 'Not tried again every minute.');

        $this->now += 900;
        $email->failure = null;
        $notifier->round();
        $text = $this->at($webhook->sent, 1, 'text');
        self::assertIsString($text);
        self::assertStringContainsString('Notifications couldn', $text, 'Its own problem was told.');
        self::assertSame([], array_filter($this->problems->open(), static fn (array $stored): bool => Problems::NOTIFY === $stored['problem']->key), 'Over once a message went through on every channel.');
    }

    public function testNothingSentIsntToldAndIsTriedAgain(): void
    {
        $email = new RecordingChannel('email', 'Connection refused.');
        $notifier = $this->notifier([$email]);
        $this->problems->sync([new Problem('disk', 'The disk is almost full.', 'Make room.')]);

        $notifier->round();
        self::assertContains('disk', $this->started(), 'Still to tell.');

        $this->now += 901;
        $email->failure = null;
        $notifier->round();
        self::assertStringContainsString('The disk is almost full.', $email->sent[0]['text']);
    }

    public function testWrongSettingsAreAProblemAndDontStopTheRest(): void
    {
        $settings = new NotifySettings(email: 'jane@example.com', webhook: 'http://hooks.example/x', secret: 'short', name: 'Hetzner 1');
        $notifier = Notifier::create($this->problems, $settings, new MockHttpClient());

        self::assertFalse($notifier->configured(), 'No mailer, and a webhook that isn\'t one: no channel.');
        $notifier->round();
        $own = $this->problems->open()[0]['problem'];
        self::assertSame(Problems::NOTIFY, $own->key);
        self::assertStringContainsString('QUIRE_SERVER_MAILER_DSN', $own->summary);
        self::assertStringContainsString('https://', $own->summary);
        self::assertStringContainsString('QUIRE_SERVER_NOTIFY_SECRET', $own->summary);

        $both = Notifier::create($this->problems, new NotifySettings(email: 'jane@example.com', mailerDsn: 'smtp://mail.example:25', webhook: 'https://hooks.example/x', secret: str_repeat('s', 32)), new MockHttpClient());
        self::assertSame(['email to jane@example.com', 'webhook to hooks.example'], $both->channels());

        $acme = Notifier::create($this->problems, new NotifySettings(acmeEmail: 'jane@example.com'), new MockHttpClient());
        self::assertFalse($acme->configured());
        self::assertSame([], new NotifySettings(acmeEmail: 'jane@example.com')->problems(), 'Let\'s Encrypt\'s address alone doesn\'t ask for emails.');
        self::assertSame(['email to jane@example.com'], Notifier::create($this->problems, new NotifySettings(mailerDsn: 'smtp://mail.example:25', acmeEmail: 'jane@example.com'), new MockHttpClient())->channels(), 'With a mailer, they go to it.');
    }

    public function testATestOnEveryChannel(): void
    {
        $email = new RecordingChannel('email');
        $webhook = new RecordingChannel('webhook', 'The webhook to hooks.example answered 500.');

        self::assertSame(['email' => null, 'webhook' => 'The webhook to hooks.example answered 500.'], $this->notifier([$email, $webhook])->test());
        self::assertSame('Quire Server Hetzner 1: a test', $email->sent[0]['subject']);
        self::assertSame('test', $email->sent[0]['payload']['event']);
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

    /**
     * @return list<string> The problems still to tell that they started
     */
    private function started(): array
    {
        return array_map(static fn (array $stored): string => $stored['problem']->key, $this->problems->due()['started']);
    }

    /**
     * @param list<Channel> $channels
     */
    private function notifier(array $channels): Notifier
    {
        return new Notifier($this->problems, new NotifySettings(name: 'Hetzner 1'), $channels, '1.2.3', fn (): int => $this->now);
    }
}
