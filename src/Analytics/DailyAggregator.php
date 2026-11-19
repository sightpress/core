<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

/**
 * Turns a day's raw hits into its figures ({@see Metric}): totals and counts per page, source, language, device,
 * missing address, bot and search query. Done in PHP rather than with `GROUP BY`, because some databases compare
 * text ignoring case or accents and would merge `/About` with `/about` or `café` with `cafe`.
 */
final readonly class DailyAggregator
{
    /**
     * @param iterable<array<string, mixed>> $hits rows of `analytics_hit` (column => value)
     *
     * @return list<array{metric: string, dimension: string, item: string, value: int}>
     */
    public function aggregate(iterable $hits): array
    {
        $counts = [];
        $visitors = [];
        foreach ($hits as $hit) {
            match (self::text($hit['kind'] ?? null)) {
                Hit::VIEW => self::view($counts, $visitors, $hit),
                Hit::NOT_FOUND => self::add($counts, Metric::NOT_FOUND, Metric::PATH, self::text($hit['path'] ?? null)),
                Hit::BOT => self::add($counts, Metric::BOT_READS, Metric::BOT, implode('|', [
                    self::text($hit['bot'] ?? null),
                    self::text($hit['bot_kind'] ?? null),
                    self::text($hit['resource_kind'] ?? null),
                ])),
                Hit::SEARCH => self::search($counts, $hit),
                default => null,
            };
        }
        foreach ($visitors as $key => $keys) {
            [$dimension, $item] = explode("\n", $key, 2);
            $counts[Metric::VISITORS][$dimension][$item] = count($keys);
        }

        $rows = [];
        ksort($counts, SORT_STRING);
        foreach ($counts as $metric => $dimensions) {
            ksort($dimensions, SORT_STRING);
            foreach ($dimensions as $dimension => $items) {
                ksort($items, SORT_STRING);
                foreach ($items as $item => $value) {
                    $rows[] = ['metric' => (string) $metric, 'dimension' => (string) $dimension, 'item' => (string) $item, 'value' => $value];
                }
            }
        }

        return $rows;
    }

    /**
     * A page read: one more view, and the visitor, in total and per page, source, language and device.
     *
     * @param array<string, array<string, array<string, int>>> $counts
     * @param array<string, array<string, true>>               $visitors dimension + item => visitor keys
     * @param array<string, mixed>                             $hit
     */
    private static function view(array &$counts, array &$visitors, array $hit): void
    {
        $locale = self::text($hit['locale'] ?? null);
        $items = [
            Metric::TOTAL => '',
            Metric::PAGE => self::text($hit['page_id'] ?? null) . ':' . $locale,
            Metric::SOURCE => self::text($hit['source'] ?? null),
            Metric::LOCALE => $locale,
            Metric::DEVICE => self::text($hit['device'] ?? null),
        ];
        $visitor = self::text($hit['visitor_key'] ?? null);
        foreach ($items as $dimension => $item) {
            self::add($counts, Metric::VIEWS, $dimension, $item);
            $visitors[$dimension . "\n" . $item][$visitor] = true;
        }
    }

    /**
     * A search: counted in total, per query kept, and as "found nothing" when it had no results.
     *
     * @param array<string, array<string, array<string, int>>> $counts
     * @param array<string, mixed>                             $hit
     */
    private static function search(array &$counts, array $hit): void
    {
        $query = self::text($hit['search_query'] ?? null);
        $metrics = (int) self::text($hit['result_count'] ?? null) === 0 ? [Metric::SEARCHES, Metric::EMPTY_SEARCHES] : [Metric::SEARCHES];
        foreach ($metrics as $metric) {
            self::add($counts, $metric, Metric::TOTAL, '');
            if ($query !== '') {
                self::add($counts, $metric, Metric::QUERY, $query);
            }
        }
    }

    /**
     * @param array<string, array<string, array<string, int>>> $counts
     */
    private static function add(array &$counts, string $metric, string $dimension, string $item): void
    {
        $counts[$metric][$dimension][$item] = ($counts[$metric][$dimension][$item] ?? 0) + 1;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
