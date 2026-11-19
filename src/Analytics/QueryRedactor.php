<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

/**
 * What analytics keeps of a site search (ADR-0012): the text lower-cased and at most {@see LENGTH} characters, or
 * nothing when it looks like personal data — an email address, a phone number or any other long number (card,
 * account or ID numbers).
 */
final readonly class QueryRedactor
{
    public const int LENGTH = 100;

    /** Digits in a row (ignoring spaces, dots, dashes, slashes and brackets) that make a "long number". */
    public const int LONG_NUMBER = 6;

    /**
     * @return non-empty-string|null null when the query must not be kept
     */
    public function redact(string $query): ?string
    {
        $query = mb_strtolower(trim((string) preg_replace('/[\s\p{Z}\p{Cc}]+/u', ' ', $query)));
        if ($query === '') {
            return null;
        }
        $email = preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+|[^\s@]+\s*(@|\(at\)|\[at\])\s*[^\s@]+/u', $query) === 1;
        $number = preg_match('/(?:\+|\b00)?\p{Nd}(?:[\s.\-\/()]*\p{Nd}){' . (self::LONG_NUMBER - 1) . ',}/u', $query) === 1;
        if ($email || $number) {
            return null;
        }
        $query = trim(mb_substr($query, 0, self::LENGTH));

        return $query === '' ? null : $query;
    }
}
