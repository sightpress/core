<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * Domain names compared the way DNS does: lower-cased, without a trailing dot, and international names (IDN) in
 * their ASCII form (`bücher.example` → `xn--bcher-kva.example`), so the same name always compares the same (X-5).
 */
final class DomainName
{
    public static function normalise(string $domain): string
    {
        $domain = rtrim(mb_strtolower(trim($domain)), '.');
        if ($domain === '' || preg_match('/^[\x20-\x7e]*$/', $domain) === 1) {
            return $domain;
        }
        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) ? $ascii : $domain;
    }

    /**
     * The domain of an email address: what follows the last `@`, normalised; empty when there is none.
     */
    public static function ofEmail(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : self::normalise(substr($email, $at + 1));
    }

    /**
     * Whether `$domain` is `$parent` or a name under it: `www.example.org` is under `example.org`, but
     * `evil-example.org` and `example.org.evil.test` aren't. Both are normalised first.
     */
    public static function isWithin(string $domain, string $parent): bool
    {
        $domain = self::normalise($domain);
        $parent = self::normalise($parent);

        return $parent !== '' && ($domain === $parent || str_ends_with($domain, '.' . $parent));
    }
}
