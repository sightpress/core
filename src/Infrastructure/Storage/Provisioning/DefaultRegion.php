<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

/**
 * The default storage location for a site (D50), from its time zone because core records no owner country: the UK
 * stays in London, the EU, EEA and Switzerland in the EU. Anywhere else has no default: the operator chooses, so data
 * never moves abroad by accident.
 */
final class DefaultRegion
{
    private const array UK = ['Europe/London', 'Europe/Belfast', 'Europe/Jersey', 'Europe/Guernsey', 'Europe/Isle_of_Man'];

    private const array EU = [
        'Europe/Amsterdam', 'Europe/Athens', 'Europe/Berlin', 'Europe/Bratislava', 'Europe/Brussels', 'Europe/Bucharest',
        'Europe/Budapest', 'Europe/Busingen', 'Europe/Copenhagen', 'Europe/Dublin', 'Europe/Helsinki', 'Europe/Lisbon', 'Europe/Ljubljana',
        'Europe/Luxembourg', 'Europe/Madrid', 'Europe/Malta', 'Europe/Mariehamn', 'Europe/Oslo', 'Europe/Paris',
        'Europe/Prague', 'Europe/Riga', 'Europe/Rome', 'Europe/Sofia', 'Europe/Stockholm', 'Europe/Tallinn',
        'Europe/Vaduz', 'Europe/Vienna', 'Europe/Vilnius', 'Europe/Warsaw', 'Europe/Zagreb', 'Europe/Zurich',
        'Atlantic/Azores', 'Atlantic/Canary', 'Atlantic/Madeira', 'Atlantic/Reykjavik', 'Africa/Ceuta', 'Asia/Nicosia',
        'Asia/Famagusta',
    ];

    public static function isUk(string $timezone): bool
    {
        return in_array($timezone, self::UK, true);
    }

    public static function isEu(string $timezone): bool
    {
        return in_array($timezone, self::EU, true);
    }

    /**
     * The AWS region for this time zone, or null when the operator must choose.
     */
    public static function aws(string $timezone): ?string
    {
        return match (true) {
            self::isUk($timezone) => 'eu-west-2',
            self::isEu($timezone) => 'eu-central-1',
            default => null,
        };
    }

    /**
     * The R2 jurisdiction for this time zone, or null when the operator must choose. R2 has no UK location, so UK
     * sites use the EU jurisdiction, and the plan says so.
     */
    public static function cloudflare(string $timezone): ?string
    {
        return self::isUk($timezone) || self::isEu($timezone) ? 'eu' : null;
    }
}
