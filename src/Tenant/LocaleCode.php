<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use InvalidArgumentException;
use Collator;
use Locale;
use ResourceBundle;

/**
 * A locale code as SightPress writes it: language, optional script, optional region, for example `en-GB`, `cy`,
 * `pt-BR` or `zh-Hant-TW`.
 */
final class LocaleCode
{
    public const string PATTERN = '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2})?$/';

    public static function isValid(string $locale): bool
    {
        return preg_match(self::PATTERN, $locale) === 1;
    }

    /**
     * Writes a locale the way SightPress does, whatever the case and with `-` or `_`: `PT_br` becomes `pt-BR`.
     *
     * @throws InvalidArgumentException when it is not a locale of a language (and region) that exists
     */
    public static function normalise(string $locale): string
    {
        $parts = preg_split('/[-_]/', trim($locale));
        $parts = $parts === false ? [] : $parts;
        $code = implode('-', array_map(
            static fn (string $part, int $index): string => match (true) {
                $index === 0 => mb_strtolower($part),
                strlen($part) === 4 => ucfirst(mb_strtolower($part)),
                default => mb_strtoupper($part),
            },
            $parts,
            array_keys($parts),
        ));

        $parsed = Locale::parseLocale($code) ?? [];
        $known = self::isValid($code)
            && Locale::getDisplayLanguage($code, 'en') !== ($parsed['language'] ?? '')
            && (!isset($parsed['script']) || Locale::getDisplayScript($code, 'en') !== $parsed['script'])
            && (!isset($parsed['region']) || Locale::getDisplayRegion($code, 'en') !== $parsed['region']);
        if (!$known) {
            throw new InvalidArgumentException(sprintf('"%s" is not a locale: use one such as en-GB or pt-BR.', trim($locale)));
        }

        return $code;
    }

    /**
     * The locale's name in plain British English, as people say it: `en-GB` is "British English", `pt-BR`
     * "Brazilian Portuguese", `de-CH` "Swiss High German", `cy` "Welsh".
     */
    public static function describe(string $locale): string
    {
        $icu = str_replace('-', '_', $locale);
        $names = ResourceBundle::create('en', 'ICUDATA-lang');
        $languages = $names?->get('Languages');
        $name = $languages instanceof ResourceBundle ? $languages->get($icu) : null;

        return is_string($name) ? $name : (string) Locale::getDisplayName($icu, 'en');
    }

    /**
     * The locale's name in its own language, as a language switcher shows it: `pt-PT` is "português europeu", `es`
     * "español", `he` "עברית"; its first letter in upper case where the language has cases.
     */
    public static function ownName(string $locale): string
    {
        $icu = str_replace('-', '_', $locale);
        $name = null;
        foreach (array_unique([$icu, (string) Locale::getPrimaryLanguage($icu)]) as $bundle) {
            $languages = ResourceBundle::create($bundle, 'ICUDATA-lang')?->get('Languages');
            $found = $languages instanceof ResourceBundle ? $languages->get($icu) : null;
            $name ??= is_string($found) && $found !== '' ? $found : null;
        }
        $name ??= (string) Locale::getDisplayName($icu, $icu);

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /**
     * Every locale the server knows, written the SightPress way, with its name: `['cy' => 'Welsh', 'en-GB' =>
     * 'British English', …]`, sorted by name.
     *
     * @return array<non-empty-string, string>
     */
    public static function available(): array
    {
        $names = [];
        $locales = ResourceBundle::getLocales('');
        foreach (is_array($locales) ? $locales : [] as $icu) {
            $code = is_string($icu) ? str_replace('_', '-', $icu) : '';
            if ($code !== '' && self::isValid($code)) {
                $names[$code] = self::describe($code);
            }
        }
        $collator = new Collator('en');
        uasort($names, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return $names;
    }
}
