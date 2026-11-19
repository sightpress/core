<?php

declare(strict_types=1);

namespace SightPress\Core\Search;

/**
 * BM25F relevance (ADR-0011): each part of a document counts with its weight (title 3×, summary 2×, body 1×), term
 * frequencies are normalised by the part's length against the average, and rarer terms count more. A group of terms
 * (the last word of a query and the longer words it starts) counts once, with its best term.
 */
final readonly class Bm25f
{
    public const int TITLE = 1;
    public const int SUMMARY = 2;
    public const int BODY = 3;

    /** @var array<int, float> */
    public const array WEIGHTS = [self::TITLE => 3.0, self::SUMMARY => 2.0, self::BODY => 1.0];

    private const float K1 = 1.2;

    private const float B = 0.75;

    /**
     * @param int                     $documents         number of documents searched
     * @param array<int, float>       $averageLengths    average number of terms of each part, by part
     * @param array<string, int>      $documentFrequency in how many documents each term is
     */
    public function __construct(
        private int $documents,
        private array $averageLengths,
        private array $documentFrequency,
    ) {
    }

    /**
     * @param list<list<string>>                 $groups      the query's terms, one group per word
     * @param array<string, array<int, int>>     $frequencies how often each term is in each part of the document
     * @param array<int, int>                    $lengths     number of terms in each part of the document
     */
    public function score(array $groups, array $frequencies, array $lengths): float
    {
        $score = 0.0;
        foreach ($groups as $terms) {
            $best = 0.0;
            foreach ($terms as $term) {
                $best = max($best, $this->termScore($term, $frequencies[$term] ?? [], $lengths));
            }
            $score += $best;
        }

        return $score;
    }

    /**
     * @param array<int, int> $frequencies by part
     * @param array<int, int> $lengths     by part
     */
    private function termScore(string $term, array $frequencies, array $lengths): float
    {
        $weighted = 0.0;
        foreach ($frequencies as $part => $frequency) {
            $average = $this->averageLengths[$part] ?? 0.0;
            $norm = $average > 0.0 ? 1.0 - self::B + self::B * (($lengths[$part] ?? 0) / $average) : 1.0;
            $weighted += (self::WEIGHTS[$part] ?? 1.0) * $frequency / $norm;
        }
        if ($weighted <= 0.0) {
            return 0.0;
        }
        $df = $this->documentFrequency[$term] ?? 0;
        $idf = log(1.0 + ($this->documents - $df + 0.5) / ($df + 0.5));

        return max(0.0, $idf) * $weighted / (self::K1 + $weighted);
    }
}
