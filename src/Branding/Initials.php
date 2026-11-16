<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

/**
 * The initials of a site's name for its generated icon: the first letter of the first word, and of the last word
 * when there are two or more ("Riverside Library" → "RL"). Letters are whole characters, accents and all.
 */
final class Initials
{
    public static function of(string $name): string
    {
        $words = preg_split('/[\s\-–—_.,;:!?\/&+]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter($words === false ? [] : $words, static fn (string $word): bool => preg_match('/[\p{L}\p{N}]/u', $word) === 1));
        if ($words === []) {
            return '?';
        }
        $initials = self::first($words[0]);
        if (count($words) > 1) {
            $initials .= self::first($words[count($words) - 1]);
        }

        return mb_strtoupper($initials);
    }

    /**
     * Whether the font that draws the PNG pictures has these characters (Latin, Greek, Cyrillic and digits).
     */
    public static function drawable(string $text): bool
    {
        return preg_match('/^[\p{Latin}\p{Greek}\p{Cyrillic}\p{N}\p{M}\p{P}\p{Zs}\'’&]+$/u', $text) === 1;
    }

    private static function first(string $word): string
    {
        return (string) grapheme_extract((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 1);
    }
}
