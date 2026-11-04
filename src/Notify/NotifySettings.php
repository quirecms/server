<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

/**
 * Who hears of problems, and how (server.env, QUIRE_SERVER_NOTIFY_*; D-35): an email address, through
 * the server's mailer (QUIRE_SERVER_MAILER_DSN), and a webhook (HTTPS, each request signed with a
 * secret only the agent reads). Neither, both or one of them.
 */
final readonly class NotifySettings
{
    /** The shortest webhook secret taken: 128 bits, as hex or anything else */
    private const int SECRET_MIN = 32;

    public function __construct(
        /** QUIRE_SERVER_NOTIFY_EMAIL: set by the owner */
        public string $email = '',
        public string $from = '',
        public string $mailerDsn = '',
        public string $webhook = '',
        public string $secret = '',
        /** What the owner calls this server, in each message ("" when unnamed) */
        public string $name = '',
        /** Let's Encrypt's address (QUIRE_SERVER_ACME_EMAIL): the owner's, when no other is set */
        public string $acmeEmail = '',
    ) {
    }

    /** Who emails go to: the address set, or the one Let's Encrypt writes to */
    public function recipient(): string
    {
        return '' !== $this->email ? $this->email : $this->acmeEmail;
    }

    /** Whether emails are sent: an address and a mailer to send with */
    public function emails(): bool
    {
        return '' !== $this->recipient() && '' !== $this->mailerDsn;
    }

    public function webhooks(): bool
    {
        return '' !== $this->webhook;
    }

    /** The address emails come from: the one set, or the owner's own (a mailer's account sends as it) */
    public function sender(): string
    {
        return '' !== $this->from ? $this->from : $this->recipient();
    }

    /**
     * What's wrong with the settings, one sentence each: none when they're right. A wrong setting
     * doesn't stop the agent (it would stop everything else too): it's a problem of its own.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return [
            ...$this->emailProblems(),
            ...$this->webhookProblems(),
            ...(1 === preg_match('/^[\x20-\x7e]{0,63}\z/', $this->name) ? [] : ['QUIRE_SERVER_NAME is a short name for this server, in plain characters.']),
        ];
    }

    /**
     * @return list<string> Why emails can't be sent as set (none: they can, or none are asked for)
     */
    public function emailProblems(): array
    {
        $problems = [];

        foreach (['QUIRE_SERVER_NOTIFY_EMAIL' => $this->email, 'QUIRE_SERVER_NOTIFY_FROM' => $this->from, 'QUIRE_SERVER_ACME_EMAIL' => $this->acmeEmail] as $setting => $address) {
            if ('' !== $address && false === filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                $problems[] = \sprintf('%s isn\'t an email address.', $setting);
            }
        }

        // Only an address set for this: Let's Encrypt's alone doesn't ask for emails
        if ('' !== $this->email && '' === $this->mailerDsn) {
            $problems[] = 'Problems aren\'t emailed: QUIRE_SERVER_MAILER_DSN, the mailer to send them with, isn\'t set.';
        }

        return $problems;
    }

    /**
     * @return list<string> Why the webhook can't be used as set (none: it can, or there's none)
     */
    public function webhookProblems(): array
    {
        if ('' === $this->webhook) {
            return [];
        }

        $problems = [];
        $url = parse_url($this->webhook);

        if (!\is_array($url) || 'https' !== ($url['scheme'] ?? null) || '' === ($url['host'] ?? '') || isset($url['user']) || isset($url['pass'])) {
            $problems[] = 'QUIRE_SERVER_NOTIFY_WEBHOOK is an https:// address (with no user or password in it).';
        }

        if (\strlen($this->secret) < self::SECRET_MIN || 1 !== preg_match('/^[\x21-\x7e]+\z/', $this->secret)) {
            $problems[] = \sprintf('QUIRE_SERVER_NOTIFY_SECRET signs each webhook request: at least %d characters, no spaces (e.g. "openssl rand -hex 32").', self::SECRET_MIN);
        }

        return $problems;
    }

    /**
     * The webhook's host, which may be shown: its path is a secret of its own at Slack and Discord.
     */
    public function webhookHost(): string
    {
        $host = parse_url($this->webhook, \PHP_URL_HOST);

        return \is_string($host) ? $host : '';
    }

    /**
     * @return list<string> Values never to be written to a log (the webhook's whole address included)
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->secret, $this->webhook, (string) parse_url($this->mailerDsn, \PHP_URL_PASS)], static fn (string $secret): bool => '' !== $secret));
    }
}
