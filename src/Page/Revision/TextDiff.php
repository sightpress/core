<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Revision;

/**
 * Compares two texts word by word: the words both have, the words only the old one has (removed) and the words only
 * the new one has (added), in reading order. Whitespace is tidied first (runs of spaces and line breaks become one
 * space), so only changes a reader notices show up.
 *
 * Very long texts with changes all over them are compared as a whole (the old text removed, the new one added), so
 * comparing never takes more than a moment.
 */
final class TextDiff
{
    /** Above this many word pairs to compare, the changed middle part is shown as removed and added as a whole. */
    public const int MAX_CELLS = 1_000_000;

    public const string SAME = 'same';
    public const string ADDED = 'added';
    public const string REMOVED = 'removed';

    /**
     * @return list<array{string, string}> `[kind, text]` with kind {@see SAME}, {@see ADDED} or {@see REMOVED};
     *                                     next to each other, parts never have the same kind
     */
    public static function compare(string $old, string $new): array
    {
        $a = self::words($old);
        $b = self::words($new);

        $start = 0;
        $max = min(count($a), count($b));
        while ($start < $max && $a[$start] === $b[$start]) {
            ++$start;
        }
        $endA = count($a);
        $endB = count($b);
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            --$endA;
            --$endB;
        }

        $parts = [];
        foreach (array_slice($a, 0, $start) as $word) {
            $parts[] = [self::SAME, $word];
        }
        $middleA = array_slice($a, $start, $endA - $start);
        $middleB = array_slice($b, $start, $endB - $start);
        array_push($parts, ...self::middle($middleA, $middleB));
        foreach (array_slice($a, $endA) as $word) {
            $parts[] = [self::SAME, $word];
        }

        return self::join($parts);
    }

    /**
     * Whether the two texts read the same once whitespace is tidied.
     */
    public static function same(string $old, string $new): bool
    {
        return self::words($old) === self::words($new);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{string, string}>
     */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0 || $n * $m > self::MAX_CELLS) {
            return [
                ...array_map(static fn (string $word): array => [self::REMOVED, $word], $a),
                ...array_map(static fn (string $word): array => [self::ADDED, $word], $b),
            ];
        }

        // Longest common subsequence, from the end, then read from the start.
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $a[$i] === $b[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $parts = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $parts[] = [self::SAME, $a[$i]];
                ++$i;
                ++$j;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $parts[] = [self::REMOVED, $a[$i++]];
            } else {
                $parts[] = [self::ADDED, $b[$j++]];
            }
        }
        while ($i < $n) {
            $parts[] = [self::REMOVED, $a[$i++]];
        }
        while ($j < $m) {
            $parts[] = [self::ADDED, $b[$j++]];
        }

        return $parts;
    }

    /**
     * Joins words of the same kind next to each other, with the spaces between them, and puts removed words before
     * added ones where they replace each other.
     *
     * @param list<array{string, string}> $words
     *
     * @return list<array{string, string}>
     */
    private static function join(array $words): array
    {
        $runs = [];
        $same = [];
        $removed = [];
        $added = [];
        foreach ([...$words, [self::SAME, null]] as [$kind, $word]) {
            if ($kind !== self::SAME) {
                if ($same !== []) {
                    $runs[] = [self::SAME, implode(' ', $same)];
                    $same = [];
                }
                if ($kind === self::REMOVED) {
                    $removed[] = $word;
                } else {
                    $added[] = $word;
                }
                continue;
            }
            if ($removed !== []) {
                $runs[] = [self::REMOVED, implode(' ', $removed)];
                $removed = [];
            }
            if ($added !== []) {
                $runs[] = [self::ADDED, implode(' ', $added)];
                $added = [];
            }
            if ($word !== null) {
                $same[] = $word;
            }
        }
        if ($same !== []) {
            $runs[] = [self::SAME, implode(' ', $same)];
        }

        return $runs;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $text = trim($text);

        $words = preg_split('/\s+/u', $text);

        return $text === '' || $words === false ? [] : $words;
    }
}
