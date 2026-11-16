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
     * Markdown text with the characters that would format it escaped.
     */
    public static function markdown(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]<>#])/', '\\\\$1', trim((string) preg_replace('/\s+/', ' ', $text)));
    }
}
