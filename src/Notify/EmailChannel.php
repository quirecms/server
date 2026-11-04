<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;

/**
 * Emails, through the server's mailer (QUIRE_SERVER_MAILER_DSN: the one sites get by default).
 */
final class EmailChannel implements Channel
{
    private ?TransportInterface $transport = null;

    /**
     * @param (\Closure(string): TransportInterface)|null $transports A transport for a DSN (tests)
     */
    public function __construct(
        private readonly NotifySettings $settings,
        private readonly ?\Closure $transports = null,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'email to '.$this->settings->recipient();
    }

    #[\Override]
    public function send(string $subject, string $text, array $payload): void
    {
        try {
            $email = new Email()
                ->from($this->settings->sender())
                ->to($this->settings->recipient())
                ->subject($subject)
                ->text($text);
            // An automatic message (RFC 3834): no auto-replies to it
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $this->transport ??= $this->transports instanceof \Closure ? ($this->transports)($this->settings->mailerDsn) : Transport::fromDsn($this->settings->mailerDsn);
            $this->transport->send($email);
        } catch (MailerException|MimeException $e) {
            // The mailer's message, without its DSN (it may hold the password)
            throw new \RuntimeException(\sprintf('The email to %s couldn\'t be sent: %s', $this->settings->recipient(), str_replace($this->settings->secrets(), '***', $e->getMessage())), 0, $e);
        }
    }
}
