<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use Transliterator;

/**
 * Names for uploaded files. The stored file gets a random name; these are only for people and URLs.
 */
final class FileName
{
    private const int SAFE_MAX_LENGTH = 80;
    private const int ORIGINAL_MAX_LENGTH = 255;

    /**
     * A name for URLs and downloads: the original name in lower-case ASCII letters, digits and hyphens (so
     * "Relatório Anual 2026.PDF" becomes `relatorio-anual-2026.pdf`), `file` when nothing is left, then the
     * extension of the detected type.
     */
    public static function safe(string $clientName, string $extension): string
    {
        $base = pathinfo(self::basename($clientName), PATHINFO_FILENAME);
        $ascii = Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($base);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', is_string($ascii) ? $ascii : strtolower($base)), '-');
        $slug = rtrim(substr($slug, 0, self::SAFE_MAX_LENGTH), '-');

        return ($slug === '' ? 'file' : $slug) . '.' . $extension;
    }

    /**
     * The original name, without folders or control characters (or empty when it isn't UTF-8) and at most 255 characters; `$fallback` when
     * nothing is left.
     */
    public static function original(string $clientName, string $fallback): string
    {
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', self::basename($clientName)));

        return $name === '' ? $fallback : mb_substr($name, 0, self::ORIGINAL_MAX_LENGTH);
    }

    /**
     * Drops any folder a browser sent along (`C:\fakepath\photo.jpg`).
     */
    private static function basename(string $name): string
    {
        $parts = preg_split('#[/\\\\]#', $name);

        return is_array($parts) ? (string) end($parts) : $name;
    }
}
