<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Publish;

/**
 * WCAG 2.2 contrast ratio of two `#rrggbb` or `#rgb` colours, from 1 (same) to 21 (black on white).
 */
final class Contrast
{
    public const float TEXT = 4.5;

    public static function ratio(string $one, string $two): float
    {
        $a = self::luminance($one);
        $b = self::luminance($two);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * `#ABC` → `#aabbcc`; anything that isn't a hex colour → null.
     */
    public static function normalise(string $colour): ?string
    {
        $colour = strtolower(trim($colour));
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $colour, $match) !== 1) {
            return null;
        }
        $hex = $match[1];

        return '#' . (strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex);
    }

    private static function luminance(string $colour): float
    {
        $hex = substr(self::normalise($colour) ?? '#000000', 1);
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
