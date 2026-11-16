<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use Transliterator;

/**
 * The last part of a page's address, for example `team` in `/about/team`: lower-case ASCII letters and digits,
 * single hyphens between them, at most 60 characters.
 */
final class Slug
{
    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const int MAX_LENGTH = 60;

    /**
     * First-level addresses that belong to the system, not to pages.
     */
    public const array RESERVED = ['account', 'admin', 'api', 'assets', 'login', 'logout', 'media', 'password', 'register', 'search'];

    public static function isValid(string $slug): bool
    {
        return strlen($slug) <= self::MAX_LENGTH && preg_match(self::PATTERN, $slug) === 1;
    }

    /**
     * A slug made from a title: "Über uns & Team!" becomes `uber-uns-team`; `page` when nothing is left.
     */
    public static function fromText(string $text): string
    {
        $ascii = Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($text);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', is_string($ascii) ? $ascii : strtolower($text)), '-');
        $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');

        return $slug === '' ? 'page' : $slug;
    }

    /**
     * Whether a top-level page may not use the slug on a site with these locales: system addresses and the
     * prefixes of the site's locales (`pt`, `pt-br`) are taken.
     *
     * @param list<string> $locales
     */
    public static function isReservedAtRoot(string $slug, array $locales): bool
    {
        if (in_array($slug, self::RESERVED, true)) {
            return true;
        }
        foreach ($locales as $locale) {
            $prefix = strtolower($locale);
            if ($slug === $prefix || $slug === explode('-', $prefix)[0]) {
                return true;
            }
        }

        return false;
    }
}
