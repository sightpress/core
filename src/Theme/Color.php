<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * A colour token, written in `theme.json` as `#rgb` or `#rrggbb` (stored as lower-case `#rrggbb`).
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
}
