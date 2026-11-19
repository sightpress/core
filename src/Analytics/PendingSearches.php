<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

/**
 * Searches made while a request is answered ({@see SearchListener}), kept until the request is told about after
 * the response ({@see AnalyticsRecorder}), which records them only when the request counts (not signed in, no
 * `Sec-GPC` / `DNT`, not a bot). Taking them empties the list, so nothing carries over to the next request.
 */
final class PendingSearches
{
    /** @var list<array{query: ?string, locale: string, results: int}> */
    private array $searches = [];

    public function add(?string $query, string $locale, int $results): void
    {
        $this->searches[] = ['query' => $query, 'locale' => $locale, 'results' => $results];
    }

    /**
     * @return list<array{query: ?string, locale: string, results: int}>
     */
    public function take(): array
    {
        $searches = $this->searches;
        $this->searches = [];

        return $searches;
    }
}
