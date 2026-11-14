<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

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
}
