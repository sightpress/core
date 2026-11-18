<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use InvalidArgumentException;
use Locale;

/**
 * Which of a site's languages to show someone who asked for another one (ADR-0007): the exact locale, then the
 * language without its region (`pt-BR` → `pt`), then the same language in another region (`pt-PT`), then the site's
 * default. A script is part of the language: `zh-Hant-TW` falls back to `zh-Hant` and `zh-Hant-HK`, never to
 * Simplified Chinese.
 *
 * Also picks a site language from an `Accept-Language` header, the same way, best quality first.
 */
final class LocaleFallback
{
    /**
     * The site's languages to try, best first; always ends with the default.
     *
     * @param list<string> $available the site's languages
     *
     * @return non-empty-list<string>
     */
    public static function chain(string $requested, array $available, string $default): array
    {
        return array_values(array_unique([...self::matches($requested, $available), $default]));
    }

    /**
     * The site language for an `Accept-Language` header, or null when nothing in it matches one (`*` and unknown
     * languages don't).
     *
     * @param list<string> $available
     */
    public static function negotiate(string $acceptLanguage, array $available): ?string
    {
        $wanted = [];
        foreach (explode(',', $acceptLanguage) as $index => $part) {
            $pieces = array_map(trim(...), explode(';', $part));
            $tag = array_shift($pieces);
            $quality = 1.0;
            foreach ($pieces as $parameter) {
                if (preg_match('/^q\s*=\s*([01](?:\.\d{1,3})?)$/i', $parameter, $match) === 1) {
                    $quality = (float) $match[1];
                }
            }
            if ($tag !== '' && $tag !== '*' && $quality > 0) {
                $wanted[] = [$quality, $index, $tag];
            }
        }
        usort($wanted, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        foreach ($wanted as [, , $tag]) {
            $found = self::matches($tag, $available);
            if ($found !== []) {
                return $found[0];
            }
        }

        return null;
    }

    /**
     * @param list<string> $available
     *
     * @return list<string>
     */
    private static function matches(string $requested, array $available): array
    {
        $requested = self::written($requested);
        // ICU reads an empty tag as the server's own locale.
        [$language, $script] = $requested === '' ? ['', null] : self::parts($requested);
        if ($language === '') {
            return [];
        }
        $base = $language . ($script === null ? '' : '-' . $script);
        $found = [];
        if (in_array($requested, $available, true)) {
            $found[] = $requested;
        }
        if (in_array($base, $available, true)) {
            $found[] = $base;
        }
        foreach ($available as $locale) {
            if (self::parts($locale) === [$language, $script]) {
                $found[] = $locale;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array{string, ?string} the lower-case language (`''` when there is none) and the script
     */
    private static function parts(string $locale): array
    {
        $parts = Locale::parseLocale(str_replace('-', '_', $locale)) ?? [];
        $language = $parts['language'] ?? '';
        $script = $parts['script'] ?? null;

        return [is_string($language) ? strtolower($language) : '', is_string($script) ? $script : null];
    }

    /**
     * The tag as SightPress writes locales (`pt-br` → `pt-BR`), or as given when it isn't one.
     */
    private static function written(string $tag): string
    {
        try {
            return LocaleCode::normalise($tag);
        } catch (InvalidArgumentException) {
            return trim($tag);
        }
    }
}
