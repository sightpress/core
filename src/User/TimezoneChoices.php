<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use DateTimeZone;

/**
 * Every IANA time zone, grouped by region for a `<select>` with `<optgroup>`s: `Europe` → `Europe/Lisbon` =>
 * "Lisbon". Places are written with spaces (`Sao Paulo`) and sub-regions with a dash (`Argentina – Buenos Aires`).
 * `UTC` is its own group, first.
 */
final class TimezoneChoices
{
    /**
     * @return array<string, array<string, string>> region => [time zone id => place]
     */
    public static function grouped(): array
    {
        $groups = ['UTC' => ['UTC' => 'UTC (Coordinated Universal Time)']];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            if ($id === 'UTC') {
                continue;
            }
            [$region, $place] = explode('/', $id, 2);
            $groups[$region][$id] = str_replace(['_', '/'], [' ', ' – '], $place);
        }

        return $groups;
    }

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }
}
