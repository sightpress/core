<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use Locale;

/**
 * The writing direction of a language, for the `dir` attribute: `rtl` for right-to-left scripts (Arabic, Hebrew,
 * Persian, Urdu …), otherwise `ltr`. A script subtag wins over the language, so `az-Arab` is `rtl` and
 * `ku-Latn` is `ltr`.
 */
final class TextDirection
{
    private const array RTL_SCRIPTS = ['Arab', 'Hebr', 'Thaa', 'Syrc', 'Nkoo', 'Adlm', 'Rohg', 'Mand', 'Samr'];

    private const array RTL_LANGUAGES = ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'iw', 'ks', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi'];

    public static function of(string $locale): string
    {
        $script = Locale::getScript($locale);
        if ($script !== '') {
            return in_array($script, self::RTL_SCRIPTS, true) ? 'rtl' : 'ltr';
        }

        return in_array(strtolower(Locale::getPrimaryLanguage($locale) ?? ''), self::RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
    }
}
