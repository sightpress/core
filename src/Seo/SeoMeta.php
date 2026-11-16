<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

/**
 * The title and description search engines get for a page, with fallbacks so every page has both: the title for
 * search engines, else the page title followed by the site's name (unless it already has it); the description,
 * else the summary, else the first words of the page, cut at a word and ended with "…".
 */
final class SeoMeta
{
    public const int DESCRIPTION_MAX = 160;

    public const int TITLE_MAX = 60;

    public static function title(?string $seoTitle, string $title, string $siteName): string
    {
        if ($seoTitle !== null) {
            return $seoTitle;
        }

        return mb_stripos($title, $siteName) !== false ? $title : $title . ' - ' . $siteName;
    }

    public static function description(?string $description, ?string $summary, string $html): ?string
    {
        $text = $description ?? $summary ?? self::plainText($html);

        return $text === '' ? null : self::trim($text, self::DESCRIPTION_MAX);
    }

    /**
     * The words of some HTML, one space between them.
     */
    public static function plainText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|template|form)\b.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<(br|/p|/h[1-6]|/li|/div|/figcaption|/td|/th|/blockquote)\b[^>]*>#i', ' $0', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * The text, at most `$max` characters: cut at the last whole word and ended with "…" when it is longer.
     */
    public static function trim(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');
        $cut = $space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : mb_substr($cut, 0, $max - 1);

        return rtrim($cut, " ,;:.-–—") . '…';
    }
}
