<?php

declare(strict_types=1);

namespace Quire\Server\Site;

/**
 * A domain a site answers on, e.g. "jane.example" or "www.jane.example". Stored in its ASCII form
 * ("xn--…" for international domain names, via UTS #46), lowercase and without a trailing dot. It
 * names files, network aliases and certificates, so only real public host names pass: at least two
 * labels, letters, digits and dashes, no IP addresses, no wildcards, no single-label names; a top-level
 * label of letters (or an international one), no special-use names (localhost, .local, .internal,
 * .onion…: never on the internet), punycode that is punycode, and no label that mixes scripts to look
 * like another (UTS #39: "pаypal" with a Cyrillic "а"). ".test" and ".example" stay allowed: for tests.
 */
final readonly class Domain implements \Stringable
{
    private const string LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

    /** Top-level names that are never on the internet (RFC 6761, 6762, 7686, 9476; ICANN's .internal) */
    private const array SPECIAL_USE = ['localhost', 'local', 'internal', 'onion', 'invalid', 'alt', 'arpa', 'home', 'lan'];

    public string $value;

    public function __construct(string $value)
    {
        $given = $value;
        $value = strtolower(trim($value));
        $value = str_ends_with($value, '.') ? substr($value, 0, -1) : $value;

        if ('' === $value || 1 === preg_match('/[\x00-\x20\x7f\/\\\\@:*?#%]/', $value)) {
            throw $this->invalid($given);
        }

        if (1 === preg_match('/[^\x00-\x7f]/', $value)) {
            $ascii = idn_to_ascii($value, \IDNA_NONTRANSITIONAL_TO_ASCII | \IDNA_USE_STD3_RULES | \IDNA_CHECK_BIDI | \IDNA_CHECK_CONTEXTJ, \INTL_IDNA_VARIANT_UTS46);

            if (false === $ascii) {
                throw $this->invalid($given);
            }

            $value = $ascii;
        }

        $labels = explode('.', $value);

        $top = $labels[\count($labels) - 1];

        // Letters, or an international top-level name: not "0x7f" or "1" (an address to some parsers),
        // nor a Docker network's name ("quire-edge")
        if (\strlen($value) > 253 || \count($labels) < 2 || 1 !== preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})\z/', $top)) {
            throw $this->invalid($given);
        }

        if (\in_array($top, self::SPECIAL_USE, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t on the internet (".%s" names are for local networks and special uses): a site needs a public domain.', SiteName::printable($given), $top));
        }

        foreach ($labels as $label) {
            if (1 !== preg_match(self::LABEL, $label)) {
                throw $this->invalid($given);
            }

            // "ab--cd" is reserved; "xn--…" must be real punycode, as it reads back
            if ('--' === substr($label, 2, 2)) {
                $decoded = str_starts_with($label, 'xn--') ? idn_to_utf8($label, \IDNA_NONTRANSITIONAL_TO_UNICODE | \IDNA_USE_STD3_RULES | \IDNA_CHECK_BIDI | \IDNA_CHECK_CONTEXTJ, \INTL_IDNA_VARIANT_UTS46, $info) : false;

                if (false === $decoded || $label !== idn_to_ascii($decoded, \IDNA_NONTRANSITIONAL_TO_ASCII | \IDNA_USE_STD3_RULES, \INTL_IDNA_VARIANT_UTS46)) {
                    throw $this->invalid($given);
                }

                $this->notConfusable($decoded, $given);
            }
        }

        $this->value = $value;
    }

    /**
     * The domain as people read it, e.g. "müller.example" for "xn--mller-kva.example".
     */
    public function unicode(): string
    {
        $unicode = idn_to_utf8($this->value, \IDNA_NONTRANSITIONAL_TO_UNICODE, \INTL_IDNA_VARIANT_UTS46);

        return false === $unicode ? $this->value : $unicode;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * A label that mixes scripts, or reads as another's in a lookalike script (UTS #39), is refused:
     * "pаypal" (with a Cyrillic "а") would pass for "paypal" in an address bar and in the server's own
     * lists. Single-script names ("пример", "例え", "müller") pass.
     */
    private function notConfusable(string $label, string $given): void
    {
        $checker = new \Spoofchecker();
        $checker->setChecks(\Spoofchecker::MIXED_SCRIPT_CONFUSABLE | \Spoofchecker::WHOLE_SCRIPT_CONFUSABLE | \Spoofchecker::INVISIBLE);
        $checker->setRestrictionLevel(\Spoofchecker::HIGHLY_RESTRICTIVE);

        if ($checker->isSuspicious($label)) {
            throw new \InvalidArgumentException(\sprintf('"%s" mixes scripts, or looks like another name in another script: refused, as it could pass for another site\'s domain.', SiteName::printable($given)));
        }
    }

    private function invalid(string $given): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('"%s" isn\'t a domain a site can have, e.g. "jane.example" or "www.jane.example".', SiteName::printable($given)));
    }
}
