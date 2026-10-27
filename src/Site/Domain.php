<?php

declare(strict_types=1);

namespace Quire\Server\Site;

/**
 * A domain a site answers on, e.g. "jane.example" or "www.jane.example". Stored in its ASCII form
 * ("xn--…" for international domain names, via UTS #46), lowercase and without a trailing dot. It
 * names files, network aliases and certificates, so only real public host names pass: at least two
 * labels, letters, digits and dashes, no IP addresses, no wildcards, no single-label names.
 */
final readonly class Domain implements \Stringable
{
    private const string LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

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

        if (\strlen($value) > 253 || \count($labels) < 2 || 1 === preg_match('/^[0-9]+\z/', $labels[\count($labels) - 1])) {
            throw $this->invalid($given);
        }

        foreach ($labels as $label) {
            if (1 !== preg_match(self::LABEL, $label)) {
                throw $this->invalid($given);
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

    private function invalid(string $given): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('"%s" isn\'t a domain a site can have, e.g. "jane.example" or "www.jane.example".', SiteName::printable($given)));
    }
}
