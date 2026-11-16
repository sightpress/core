<?php

declare(strict_types=1);

namespace SightPress\Core\User\Avatar;

/**
 * Makes the avatar everyone gets until they upload a picture: their initials in white on a coloured square.
 *
 * Initials are the first grapheme (what a reader sees as one character, accents and all) of the first and the last
 * name, upper-cased for the person's language: `Zoë Ødegaard` → `ZØ`, `李 小龙` → `李小`, `ilkay` in Turkish
 * → `İ`. The colour is picked from {@see PALETTE} by the user id, so it never changes; every colour has a contrast of
 * at least 4.5:1 with white (WCAG 1.4.3), checked by the tests.
 */
final readonly class AvatarGenerator
{
    public const string TEXT_COLOR = '#ffffff';

    /** Background colours, each with ≥ 4.5:1 contrast against {@see TEXT_COLOR}. */
    public const array PALETTE = [
        '#1d4ed8', // blue
        '#047857', // green
        '#b91c1c', // red
        '#6d28d9', // purple
        '#a21caf', // magenta
        '#0e7490', // teal
        '#9a3412', // rust
        '#374151', // slate
        '#4338ca', // indigo
        '#be185d', // pink
    ];

    public function colorFor(int $userId): string
    {
        return self::PALETTE[abs($userId) % count(self::PALETTE)];
    }

    /**
     * @param ?string $locale the person's language (BCP 47, for example `tr-TR`); null for the default rules
     */
    public function initials(string $firstName, string $lastName, ?string $locale = null): string
    {
        return self::upper(self::first($firstName), $locale) . self::upper(self::first($lastName), $locale);
    }

    /**
     * A square SVG of the initials on the colour. It contains text only: no scripts, links or external files.
     */
    public function svg(string $initials, string $color): string
    {
        $text = htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fontSize = mb_strlen($initials) > 2 ? 44 : 56;

        return '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">'
            . '<rect width="256" height="256" fill="' . htmlspecialchars($color, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>'
            . '<text x="128" y="128" dy="0.35em" text-anchor="middle" fill="' . self::TEXT_COLOR . '"'
            . ' font-family="system-ui, -apple-system, \'Segoe UI\', Roboto, \'Noto Sans\', sans-serif"'
            . ' font-size="' . $fontSize . '" font-weight="600">' . $text . '</text></svg>';
    }

    private static function first(string $name): string
    {
        $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
        $name = trim(is_string($normalized) ? $normalized : $name);
        $first = $name === '' ? false : grapheme_substr($name, 0, 1);

        return is_string($first) ? $first : '';
    }

    private static function upper(string $grapheme, ?string $locale): string
    {
        $language = strtolower(substr((string) $locale, 0, 2));
        if (in_array($language, ['tr', 'az'], true) && $grapheme === 'i') {
            return 'İ';
        }

        return mb_strtoupper($grapheme, 'UTF-8');
    }
}
