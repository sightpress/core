<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

/**
 * Sizes as PHP writes them in `php.ini` (`2M`, `512K`, `1G`, `8388608`) and back.
 */
final class IniSize
{
    private const int KILOBYTE = 1024;

    /**
     * Bytes from an ini value, read the way PHP reads it: a whole number with an optional `K`, `M` or `G`. Zero, a
     * negative number or an empty value mean "no limit" (null).
     */
    public static function bytes(string|false $value): ?int
    {
        if ($value === false || preg_match('/^\s*(-?\d+)\s*([kmg]?)/i', $value, $parts) !== 1) {
            return null;
        }
        $number = (int) $parts[1];
        if ($number <= 0) {
            return null;
        }

        return $number * match (strtolower($parts[2])) {
            'g' => self::KILOBYTE ** 3,
            'm' => self::KILOBYTE ** 2,
            'k' => self::KILOBYTE,
            default => 1,
        };
    }

    /**
     * An ini value for at least the given bytes, rounded up to whole megabytes (`65M`), or gigabytes when exact
     * (`1G`).
     */
    public static function shorthand(int $bytes): string
    {
        $megabytes = max(1, (int) ceil($bytes / self::KILOBYTE ** 2));

        return $megabytes % self::KILOBYTE === 0 ? ($megabytes / self::KILOBYTE) . 'G' : $megabytes . 'M';
    }
}
