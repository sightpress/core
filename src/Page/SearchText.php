<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

/**
 * Plain text from the Markdown of a page's blocks, for site search: link and image text kept (alt text, download
 * names), addresses and Markdown marks left out, white space made single spaces.
 */
final class SearchText
{
    private const string ESCAPED = "\u{E000}";

    private const string ESCAPED_END = "\u{E001}";

    public static function fromMarkdown(string $markdown): string
    {
        // Escaped characters (`\*`, `\[`) are text: hide them from the rules below, then put them back.
        $text = (string) preg_replace_callback('/\\\\([!-\/:-@\[-`{-~])/', static fn (array $match): string => self::ESCAPED . bin2hex($match[1]) . self::ESCAPED_END, $markdown);
        // The words the media player puts around a recording's title and transcript are not the page's own.
        $text = (string) preg_replace(['/(\[|\*\*)(?:Video|Audio): /', '/^Transcript:$/m'], ['$1', ''], $text);
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $text = (string) preg_replace('/<(?:https?|mailto):[^>\s]*>/iu', ' ', $text);
        $text = (string) preg_replace('/^[ \t]*(?:(?:#{1,6}|>|[-*+]|\d+[.)])[ \t]+)+/mu', '', $text);
        $text = (string) preg_replace('/^[ \t]*\|?(?:[ \t]*:?-{3,}:?[ \t]*\|?)+[ \t]*$/mu', ' ', $text);
        $text = str_replace(['**', '__', '`', '|'], ['', '', '', ' '], $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])[*_]+|[*_]+(?![\p{L}\p{N}])/u', '', $text);
        $text = (string) preg_replace_callback('/' . self::ESCAPED . '([0-9a-f]{2})' . self::ESCAPED_END . '/u', static fn (array $match): string => (string) hex2bin($match[1]), $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
