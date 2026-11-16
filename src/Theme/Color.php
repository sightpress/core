<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * A colour token, written in `theme.json` as `#rgb` or `#rrggbb` (stored as lower-case `#rrggbb`), with the WCAG
 * luminance and contrast maths.
 */
final readonly class Color
{
    /**
     * @param non-empty-string $hex lower-case `#rrggbb`
     */
    private function __construct(public string $hex)
    {
    }

    /**
     * @throws ThemeException when the value is not a `#rgb` or `#rrggbb` colour
     */
    public static function fromHex(string $value): self
    {
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value) !== 1) {
            throw new ThemeException(sprintf('"%s" is not a colour: write it as #rgb or #rrggbb, for example #1a1a1f.', $value));
        }

        $hex = strtolower(substr($value, 1));
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return new self('#' . $hex);
    }

    /**
     * Relative luminance (WCAG 2.2): 0 for black, 1 for white.
     */
    public function luminance(): float
    {
        $channels = [];
        foreach (str_split(substr($this->hex, 1), 2) as $pair) {
            $value = hexdec($pair) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Contrast ratio with another colour (WCAG 2.2), from 1 (the same) to 21 (black on white).
     */
    public function contrastWith(self $other): float
    {
        $lighter = max($this->luminance(), $other->luminance());
        $darker = min($this->luminance(), $other->luminance());

        return ($lighter + 0.05) / ($darker + 0.05);
    }
}
