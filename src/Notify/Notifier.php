<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

/**
 * Tells the owner (D-35): each round of the agent, what started, what's still there a week on and
 * what's over, in one message per channel. The owner hears of each problem once when it starts and
 * once when it's over, not every night. A message that can't be sent is tried again later, and is a
 * problem itself (told on the other channel, shown by "quire-server status").
 *
 * @phpstan-import-type Stored from Problems
 */
final class Notifier
{
    /** After a failed send, the next try waits this long (seconds) */
    private const int RETRY_AFTER = 900;

    private ?int $failedAt = null;

    /** @var list<string> Why channels failed at the last try */
    private array $failures = [];

    /**
     * @param list<Channel>        $channels
     * @param \Closure(): int|null $now
     */
    public function __construct(
        private readonly Problems $problems,
        private readonly NotifySettings $settings,
        private readonly array $channels,
        private readonly string $version = '',
        private readonly ?\Closure $now = null,
    ) {
    }

    /**
     * Tells what's due, if anything.
     *
     * @return list<string> What was sent, or why it wasn't, for the agent's log
     */
    public function round(): array
    {
        $this->problems->forgetUntold();

        if ([] === $this->channels || (null !== $this->failedAt && $this->time() - $this->failedAt < self::RETRY_AFTER)) {
            $this->failed(null);

            return [];
        }

        $due = $this->problems->due();

        if ([] === $due['started'] && [] === $due['reminded'] && [] === $due['over']) {
            $this->failed(null);

            return [];
        }

        [$subject, $text, $payload] = $this->message($due);
        $failures = $this->sendAll($subject, $text, $payload);

        if (\count($failures) < \count($this->channels)) {
            $this->problems->told(
                array_map(static fn (array $stored): string => $stored['problem']->key, [...$due['started'], ...$due['reminded']]),
                array_map(static fn (array $stored): string => $stored['problem']->key, $due['over']),
            );
        }

        $this->failed($failures);

        return [\sprintf('Told: %s%s', $subject, [] === $failures ? '' : ' (but: '.implode('; ', $failures).')')];
    }

    /**
     * A test message on every channel.
     *
     * @return array<string, ?string> Each channel's name, with why it failed (null: sent)
     */
    public function test(): array
    {
        $results = [];

        foreach ($this->channels as $channel) {
            try {
                $channel->send($this->subject('a test'), $this->opening()."This is a test: problems that need you will be told here, once when they start and once when they're over.\n", $this->envelope('test', []));
                $results[$channel->name()] = null;
            } catch (\RuntimeException $e) {
                $results[$channel->name()] = $e->getMessage();
            }
        }

        $this->failed(array_values(array_filter($results, is_string(...))));

        return $results;
    }

    /**
     * The notifier for the settings: a channel for each that's set and right.
     */
    public static function create(Problems $problems, NotifySettings $settings, \Symfony\Contracts\HttpClient\HttpClientInterface $client, string $version = ''): self
    {
        $channels = [];

        if ($settings->emails() && [] === $settings->emailProblems()) {
            $channels[] = new EmailChannel($settings);
        }

        if ($settings->webhooks() && [] === $settings->webhookProblems()) {
            $channels[] = new WebhookChannel($settings, $client);
        }

        return new self($problems, $settings, $channels, $version);
    }

    /** Whether anyone is told at all */
    public function configured(): bool
    {
        return [] !== $this->channels;
    }

    /**
     * @return list<string> The channels' names
     */
    public function channels(): array
    {
        return array_map(static fn (Channel $channel): string => $channel->name(), $this->channels);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string> Why each channel that failed did
     */
    private function sendAll(string $subject, string $text, array $payload): array
    {
        $failures = [];

        foreach ($this->channels as $channel) {
            try {
                $channel->send($subject, $text, $payload);
            } catch (\RuntimeException $e) {
                $failures[] = $e->getMessage();
            }
        }

        return $failures;
    }

    /**
     * The notifier's own problem: settings that aren't right (said where the owner looks, without
     * stopping the channels that are), and channels that failed. Over once there's neither.
     *
     * @param list<string>|null $failures Null: no channel was tried (what failed before still holds)
     */
    private function failed(?array $failures): void
    {
        if (null !== $failures) {
            $this->failedAt = [] === $failures ? null : $this->time();
            $this->failures = $failures;
        }

        $wrong = $this->settings->problems();

        if ([] === $wrong && [] === $this->failures) {
            $this->problems->clear(Problems::NOTIFY);

            return;
        }

        $this->problems->raise(new Problem(
            Problems::NOTIFY,
            trim(([] === $wrong ? '' : 'Notifications aren\'t set up right: '.implode(' ', $wrong)).' '.([] === $this->failures ? '' : 'Notifications couldn\'t be sent: '.implode(' ', $this->failures))),
            'Fix it in server.env (QUIRE_SERVER_MAILER_DSN, QUIRE_SERVER_NOTIFY_*), then "quire-server up"; "quire-server notify:test" tries it. Problems are kept, and told once it works.',
        ));
    }

    /**
     * @param array{started: list<Stored>, reminded: list<Stored>, over: list<Stored>} $due
     *
     * @return array{string, string, array<string, mixed>}
     */
    private function message(array $due): array
    {
        $parts = array_filter([
            \count($due['started']) > 0 ? \sprintf('%d new %s', \count($due['started']), 1 === \count($due['started']) ? 'problem' : 'problems') : null,
            \count($due['reminded']) > 0 ? \sprintf('%d still there', \count($due['reminded'])) : null,
            \count($due['over']) > 0 ? \sprintf('%d fixed', \count($due['over'])) : null,
        ]);
        $only = [...$due['started'], ...$due['reminded'], ...$due['over']];
        $subject = 1 === \count($only) ? rtrim(([] === $due['over'] ? '' : 'Fixed: ').$only[0]['problem']->summary, '.') : implode(', ', $parts);
        $text = $this->opening();
        $payload = [];

        foreach (['started' => 'New:', 'reminded' => 'Still there:', 'over' => 'Fixed:'] as $kind => $heading) {
            if ([] === $due[$kind]) {
                continue;
            }

            $text .= $heading."\n";

            foreach ($due[$kind] as $stored) {
                $problem = $stored['problem'];
                $text .= '- '.$problem->summary."\n";
                $text .= 'over' === $kind
                    ? \sprintf("  From %s to %s.\n", $this->when($stored['since']), $this->when($stored['resolved_at'] ?? $stored['since']))
                    : \sprintf("  %s\n  Since %s (%s).\n", $problem->advice, $this->when($stored['since']), $problem->key);
                $payload[] = [
                    'event' => ['started' => 'started', 'reminded' => 'reminder', 'over' => 'fixed'][$kind],
                    'key' => $problem->key,
                    'site' => $problem->site,
                    'summary' => $problem->summary,
                    'advice' => $problem->advice,
                    'operation' => $problem->operation,
                    'since' => $stored['since']->format(\DATE_ATOM),
                    'fixed_at' => $stored['resolved_at']?->format(\DATE_ATOM),
                ];
            }

            $text .= "\n";
        }

        $text .= "\"quire-server status\" shows every problem. You're told again when one is fixed, or in a week if it isn't; \"quire-server status --dismiss=<key>\" stops that for one.\n";

        return [$this->subject($subject), $text, $this->envelope('problems', $payload)];
    }

    /**
     * The webhook's data: what it's about, from which server, sent when (a receiver refuses an old
     * request replayed), and the problems.
     *
     * @param list<array<string, mixed>> $problems
     *
     * @return array<string, mixed>
     */
    private function envelope(string $event, array $problems): array
    {
        return [
            'event' => $event,
            'server' => '' === $this->settings->name ? null : $this->settings->name,
            'version' => '' === $this->version ? null : $this->version,
            'sent_at' => new \DateTimeImmutable('@'.$this->time())->format(\DATE_ATOM),
            'problems' => $problems,
        ];
    }

    private function subject(string $what): string
    {
        return \sprintf('Quire Server%s: %s', '' === $this->settings->name ? '' : ' '.$this->settings->name, $what);
    }

    private function opening(): string
    {
        return \sprintf("On %s, %s:\n\n", '' === $this->settings->name ? 'your Quire Server' : $this->settings->name, $this->when(new \DateTimeImmutable('@'.$this->time())));
    }

    private function time(): int
    {
        return $this->now instanceof \Closure ? ($this->now)() : time();
    }

    private function when(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';
    }
}
