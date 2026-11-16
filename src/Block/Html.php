<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

/**
 * Escaping for block HTML.
 */
final class Html
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * A string from block data, or `''`.
     *
     * @param array<string, mixed> $data
     */
    public static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Plain text as HTML paragraphs: a blank line starts a paragraph, a single line break stays a line break.
     */
    public static function paragraphs(string $text): string
    {
        $html = '';
        foreach (self::split($text) as $paragraph) {
            $lines = preg_split('/\R/u', $paragraph);
            $html .= '<p>' . implode('<br>', array_map(self::e(...), is_array($lines) ? $lines : [$paragraph])) . '</p>';
        }

        return $html;
    }

    /**
     * Plain text as Markdown paragraphs, escaped.
     */
    public static function markdownParagraphs(string $text): string
    {
        return implode("\n\n", array_map(self::markdown(...), self::split($text)));
    }

    /**
     * @return list<string> the non-empty paragraphs of plain text
     */
    private static function split(string $text): array
    {
        $paragraphs = preg_split('/\R[ \t]*\R\s*/u', trim($text));
        $paragraphs = is_array($paragraphs) ? $paragraphs : [$text];

        return array_values(array_filter(array_map('trim', $paragraphs), static fn (string $paragraph): bool => $paragraph !== ''));
    }

    /**
     * Markdown text with the characters that would format it escaped.
     */
    public static function markdown(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]<>#])/', '\\\\$1', trim((string) preg_replace('/\s+/', ' ', $text)));
    }
}
