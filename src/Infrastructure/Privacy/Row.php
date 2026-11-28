<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Privacy;

/**
 * Reads the values of a database row for *Download your data*: drivers give numbers and dates as strings or numbers.
 */
final class Row
{
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * A date column (UTC) as ISO 8601.
     */
    public static function date(mixed $value): ?string
    {
        return ExportedDate::of(self::text($value));
    }
}
