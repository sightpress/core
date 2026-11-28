<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Privacy;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * Dates in *Download your data* (ADR-0077): ISO 8601 in UTC, from a date column (stored as UTC) or a date object.
 */
final class ExportedDate
{
    public static function of(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            try {
                $value = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (Exception) {
                return $value;
            }
        }

        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
