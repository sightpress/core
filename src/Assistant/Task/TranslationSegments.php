<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Page\Contract\TranslationSource;

/**
 * The texts of a page to translate, in a fixed order (A-6): title, summary, search title, description, then every
 * text of the blocks, columns included ({@see KEYS}: headings, rich text, alt text, captions, titles, transcripts,
 * button texts). Everything else — block types, media, link addresses, settings — is never sent, and is put back as
 * it was, so a translation always has the same blocks.
 */
final class TranslationSegments
{
    /** Block data members that are text people read. */
    public const array KEYS = ['text', 'html', 'alt', 'caption', 'title', 'transcript'];

    /**
     * @return list<string>
     */
    public static function of(TranslationSource $source): array
    {
        $segments = [$source->title, $source->summary ?? '', $source->seoTitle ?? '', $source->description ?? ''];
        self::walk($source->blocks, static function (string $text) use (&$segments): string {
            $segments[] = $text;

            return $text;
        });

        return $segments;
    }

    /**
     * The source with every text replaced by the translation at its place.
     *
     * @param list<string> $translated as many as {@see of()} gave, in the same order
     */
    public static function apply(TranslationSource $source, array $translated): TranslationSource
    {
        $next = 4;
        /** @var list<array{type: string, version: int, data: array<string, mixed>}> $blocks */
        $blocks = self::walk($source->blocks, static function () use ($translated, &$next): string {
            return $translated[$next++] ?? '';
        });
        $optional = static fn (?string $original, string $text): ?string => $original === null || trim($text) === '' ? $original : $text;

        return new TranslationSource($translated[0] ?? $source->title, $optional($source->summary, $translated[1] ?? ''), $optional($source->seoTitle, $translated[2] ?? ''), $optional($source->description, $translated[3] ?? ''), $blocks);
    }

    /**
     * @param array<array-key, mixed>   $data
     * @param callable(string): string $text
     *
     * @return array<array-key, mixed>
     */
    private static function walk(array $data, callable $text): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::walk($value, $text);
            } elseif (is_string($value) && in_array($key, self::KEYS, true) && trim($value) !== '') {
                $data[$key] = $text($value);
            }
        }

        return $data;
    }
}
