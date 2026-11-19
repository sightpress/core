<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Http;

/**
 * The bit of a result's text shown under its title: about {@see LENGTH} characters around the first matched word,
 * as plain text in pieces, with the matched words marked. Templates print each piece escaped, inside `<mark>` when
 * it matched, so nothing from the document or the query ever becomes HTML.
 */
final class SearchExtract
{
    public const int LENGTH = 240;

    /** Scripts written without spaces between words. */
    private const string WITHOUT_SPACES = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u';

    /**
     * @param list<string> $words the matched words, as they are written in the text
     *
     * @return list<array{text: string, mark: bool}> empty when there is no text
     */
    public static function of(string $summary, string $body, array $words): array
    {
        $pattern = self::pattern($words);
        $text = self::clean($summary);
        $body = self::clean($body);
        if ($text === '' || ($pattern !== null && preg_match($pattern, $text) !== 1 && $body !== '' && preg_match($pattern, $body) === 1)) {
            $text = $body;
        }
        if ($text === '') {
            return [];
        }

        return self::pieces(self::window($text, $pattern), $pattern);
    }

    /**
     * A regular expression matching any of the words, case-insensitively: on their own (not inside another word),
     * except in scripts written without spaces (Chinese, Japanese, Thai …), where words touch.
     *
     * @param list<string> $words
     */
    private static function pattern(array $words): ?string
    {
        $words = array_values(array_unique(array_filter(array_map(trim(...), $words), static fn (string $word): bool => $word !== '')));
        if ($words === []) {
            return null;
        }
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $alternatives = array_map(
            static fn (string $word): string => preg_match(self::WITHOUT_SPACES, $word) === 1
                ? preg_quote($word, '/')
                : '(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])',
            $words,
        );

        return '/(?:' . implode('|', $alternatives) . ')/iu';
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * About LENGTH characters of the text, starting a little before the first match, cut at spaces, with "…" where
     * text was left out.
     */
    private static function window(string $text, ?string $pattern): string
    {
        if (mb_strlen($text) <= self::LENGTH) {
            return $text;
        }
        $start = 0;
        if ($pattern !== null && preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $start = max(0, mb_strlen(substr($text, 0, $match[0][1])) - intdiv(self::LENGTH, 4));
        }
        $start = min($start, mb_strlen($text) - self::LENGTH);
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = $space === false ? $start : $space + 1;
        }
        $end = $start + self::LENGTH;
        $cut = mb_substr($text, $start, self::LENGTH);
        if ($end < mb_strlen($text)) {
            $space = mb_strrpos($cut, ' ');
            $cut = ($space === false ? $cut : mb_substr($cut, 0, $space)) . ' …';
        }

        return ($start > 0 ? '… ' : '') . $cut;
    }

    /**
     * @return list<array{text: string, mark: bool}>
     */
    private static function pieces(string $text, ?string $pattern): array
    {
        if ($pattern === null) {
            return [['text' => $text, 'mark' => false]];
        }
        $parts = [];
        $offset = 0;
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$word, $at]) {
            if ($at > $offset) {
                $parts[] = ['text' => substr($text, $offset, $at - $offset), 'mark' => false];
            }
            $parts[] = ['text' => $word, 'mark' => true];
            $offset = $at + strlen($word);
        }
        if ($offset < strlen($text)) {
            $parts[] = ['text' => substr($text, $offset), 'mark' => false];
        }

        return $parts;
    }
}
