<?php

declare(strict_types=1);

namespace Quire\Server\Notify;

/**
 * The front door's certificates, as Caddy keeps them (its data volume, mounted read-only:
 * caddy/certificates/<issuer>/<domain>/<domain>.crt), by domain: when each expires, and how long it
 * was issued for. Only the certificates are read, never their keys.
 */
final readonly class Certificates
{
    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * The newest certificate for the domain, or null when there's none (yet).
     *
     * @return array{not_before: \DateTimeImmutable, not_after: \DateTimeImmutable}|null
     */
    public function of(string $domain): ?array
    {
        $newest = null;

        // One folder per issuer (Let's Encrypt, ZeroSSL, Caddy's own authority for tests)
        $files = glob($this->directory.'/*/'.$domain.'/'.$domain.'.crt');

        foreach (false === $files ? [] : $files as $file) {
            if (is_link($file) || !is_file($file)) {
                continue;
            }

            $certificate = @openssl_x509_parse((string) file_get_contents($file, length: 1 << 20));

            if (!\is_array($certificate) || !\is_int($certificate['validFrom_time_t'] ?? null) || !\is_int($certificate['validTo_time_t'] ?? null)) {
                continue;
            }

            $read = [
                'not_before' => new \DateTimeImmutable('@'.$certificate['validFrom_time_t']),
                'not_after' => new \DateTimeImmutable('@'.$certificate['validTo_time_t']),
            ];

            if (null === $newest || $read['not_after'] > $newest['not_after']) {
                $newest = $read;
            }
        }

        return $newest;
    }

    /**
     * When a certificate should have been renewed by: Caddy renews with a third of its life left; past
     * a sixth (two weeks at most), renewing has been failing for days.
     *
     * @param array{not_before: \DateTimeImmutable, not_after: \DateTimeImmutable} $certificate
     */
    public static function overdue(array $certificate, \DateTimeImmutable $now): bool
    {
        $life = $certificate['not_after']->getTimestamp() - $certificate['not_before']->getTimestamp();
        $left = $certificate['not_after']->getTimestamp() - $now->getTimestamp();

        return $left < min(14 * 86400, intdiv(max(0, $life), 6));
    }
}
