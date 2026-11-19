<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\DailyAggregator;
use SightPress\Core\Analytics\Metric;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Tenant\Site;

/**
 * The site's analytics as of one moment: the daily figures of finished days plus the raw hits of the days not
 * rolled up yet (today, so it is live). Every number for a range is the sum of its days; visitors are counted once
 * per day, so "visitors" over a week is the sum of seven days' visitors (D20).
 */
final class AnalyticsReport
{
    /** The reports {@see table()} knows, by name. */
    public const array TABLES = ['summary', 'daily', 'pages', 'sources', 'languages', 'devices', 'searches', 'searches-without-results', 'not-found', 'bots'];

    public readonly string $today;

    /** @var array<string, list<array{metric: string, dimension: string, item: string, value: int}>>|null day => figures */
    private ?array $live = null;

    public function __construct(
        private readonly AnalyticsStore $store,
        private readonly DailyAggregator $aggregator,
        private readonly PageLinks $pages,
        private readonly Site $site,
        DateTimeImmutable $now,
    ) {
        $this->today = $now->setTimezone(new DateTimeZone($site->defaultTimezone))->format('Y-m-d');
    }

    /**
     * The site's time zone, which days are counted in.
     */
    public function timeZone(): string
    {
        return $this->site->defaultTimezone;
    }

    /**
     * The last `$days` days, ending today.
     */
    public function lastDays(int $days): DateRange
    {
        return DateRange::last($days, $this->today);
    }

    /**
     * The first day there is anything for, or null when nothing has been counted yet.
     */
    public function firstDay(): ?string
    {
        return $this->store->firstDay();
    }

    public function summary(DateRange $range): Summary
    {
        $current = $this->totals($range);
        $previous = $this->totals($range->previous());

        return new Summary(
            $range,
            new Figure($current[Metric::VISITORS], $previous[Metric::VISITORS]),
            new Figure($current[Metric::VIEWS], $previous[Metric::VIEWS]),
            new Figure(self::perVisitor($current), self::perVisitor($previous)),
        );
    }

    /**
     * Visitors and page views for each day of the range, 0 on days with none.
     *
     * @return list<array{day: string, visitors: int, views: int}>
     */
    public function daily(DateRange $range): array
    {
        $values = [];
        foreach ($this->figures([Metric::VISITORS, Metric::VIEWS], Metric::TOTAL, $range) as $figure) {
            $values[$figure['day']][$figure['metric']] = ($values[$figure['day']][$figure['metric']] ?? 0) + $figure['value'];
        }
        $days = [];
        foreach ($range->each() as $day) {
            $days[] = ['day' => $day, 'visitors' => $values[$day][Metric::VISITORS] ?? 0, 'views' => $values[$day][Metric::VIEWS] ?? 0];
        }

        return $days;
    }

    /**
     * The pages read most, with their title and address now (empty for pages deleted since).
     *
     * @return list<array{page_id: int, locale: string, title: string, path: string, views: int, visitors: int}>
     */
    public function pages(DateRange $range, ?int $limit = null): array
    {
        $rows = [];
        foreach ($this->ranked(Metric::PAGE, $range, $limit) as $item => $values) {
            [$id, $locale] = array_pad(explode(':', $item, 2), 2, '');
            $rows[] = ['page_id' => (int) $id, 'locale' => $locale, 'title' => '', 'path' => '', 'views' => $values[Metric::VIEWS], 'visitors' => $values[Metric::VISITORS]];
        }
        $byLocale = [];
        foreach ($rows as $row) {
            $byLocale[$row['locale']][] = $row['page_id'];
        }
        $links = [];
        foreach ($byLocale as $locale => $ids) {
            $links[$locale] = $this->pages->find(array_values(array_unique($ids)), (string) $locale);
        }
        foreach ($rows as $index => $row) {
            $link = $links[$row['locale']][$row['page_id']] ?? null;
            if ($link !== null) {
                $rows[$index]['title'] = $link->title;
                $rows[$index]['path'] = $link->url;
            }
        }

        return $rows;
    }

    /**
     * Where visitors came from, most visitors first: `type` is `direct`, `search` (a search engine), `ai` (an AI assistant), `site` (a
     * link on another site) or `campaign` (`utm_source` / `ref`); `source` its name, host or label.
     *
     * @return list<array{type: string, source: string, visitors: int, views: int}>
     */
    public function sources(DateRange $range, ?int $limit = null): array
    {
        $rows = [];
        foreach ($this->ranked(Metric::SOURCE, $range, $limit, Metric::VISITORS) as $item => $values) {
            [$type, $source] = array_pad(explode(':', $item, 2), 2, '');
            $rows[] = ['type' => $type, 'source' => $source, 'visitors' => $values[Metric::VISITORS], 'views' => $values[Metric::VIEWS]];
        }

        return $rows;
    }

    /**
     * @return list<array{locale: string, visitors: int, views: int}>
     */
    public function languages(DateRange $range): array
    {
        $rows = [];
        foreach ($this->ranked(Metric::LOCALE, $range, by: Metric::VISITORS) as $item => $values) {
            $rows[] = ['locale' => $item, 'visitors' => $values[Metric::VISITORS], 'views' => $values[Metric::VIEWS]];
        }

        return $rows;
    }

    /**
     * @return list<array{device: string, visitors: int, views: int}>
     */
    public function devices(DateRange $range): array
    {
        $rows = [];
        foreach ($this->ranked(Metric::DEVICE, $range, by: Metric::VISITORS) as $item => $values) {
            $rows[] = ['device' => $item, 'visitors' => $values[Metric::VISITORS], 'views' => $values[Metric::VIEWS]];
        }

        return $rows;
    }

    /**
     * All site searches in the range, and those that found nothing (queries that looked like personal data count
     * here but aren't listed anywhere).
     *
     * @return array{searches: int, without_results: int}
     */
    public function searchTotals(DateRange $range): array
    {
        $searches = 0;
        $empty = 0;
        foreach ($this->figures([Metric::SEARCHES, Metric::EMPTY_SEARCHES], Metric::TOTAL, $range) as $figure) {
            if ($figure['metric'] === Metric::SEARCHES) {
                $searches += $figure['value'];
            } else {
                $empty += $figure['value'];
            }
        }

        return ['searches' => $searches, 'without_results' => $empty];
    }

    /**
     * The queries searched for most.
     *
     * @return list<array{query: string, searches: int, without_results: int}>
     */
    public function searches(DateRange $range, ?int $limit = null): array
    {
        $rows = [];
        foreach ($this->sum([Metric::SEARCHES, Metric::EMPTY_SEARCHES], Metric::QUERY, $range, Metric::SEARCHES, $limit) as $query => $values) {
            $rows[] = ['query' => (string) $query, 'searches' => $values[Metric::SEARCHES] ?? 0, 'without_results' => $values[Metric::EMPTY_SEARCHES] ?? 0];
        }

        return $rows;
    }

    /**
     * The queries that found nothing, most often first: pages people looked for and didn't find.
     *
     * @return list<array{query: string, searches: int}>
     */
    public function searchesWithoutResults(DateRange $range, ?int $limit = null): array
    {
        $rows = [];
        foreach ($this->sum([Metric::EMPTY_SEARCHES], Metric::QUERY, $range, Metric::EMPTY_SEARCHES, $limit) as $query => $values) {
            $rows[] = ['query' => (string) $query, 'searches' => $values[Metric::EMPTY_SEARCHES] ?? 0];
        }

        return $rows;
    }

    /**
     * Addresses people asked for that don't exist, most often first (without the query string).
     *
     * @return list<array{path: string, requests: int}>
     */
    public function notFound(DateRange $range, ?int $limit = null): array
    {
        $rows = [];
        foreach ($this->sum([Metric::NOT_FOUND], Metric::PATH, $range, Metric::NOT_FOUND, $limit) as $path => $values) {
            $rows[] = ['path' => (string) $path, 'requests' => $values[Metric::NOT_FOUND] ?? 0];
        }

        return $rows;
    }

    /**
     * What bots read, by bot, kind (`search`, `ai_search`, `ai_training`, `other`) and resource (`page`, `markdown`,
     * `sitemap`, `robots`, `llms`, `llms_full`).
     *
     * @return list<array{bot: string, kind: string, resource: string, reads: int}>
     */
    public function bots(DateRange $range, ?int $limit = null): array
    {
        $rows = [];
        foreach ($this->sum([Metric::BOT_READS], Metric::BOT, $range, Metric::BOT_READS, $limit) as $item => $values) {
            [$bot, $kind, $resource] = array_pad(explode('|', (string) $item, 3), 3, '');
            $rows[] = ['bot' => $bot, 'kind' => $kind, 'resource' => $resource, 'reads' => $values[Metric::BOT_READS] ?? 0];
        }

        return $rows;
    }

    /**
     * One report by name ({@see TABLES}) as a table, for the CLI and CSV downloads.
     *
     * @throws InvalidArgumentException for a name it doesn't know
     */
    public function table(string $name, DateRange $range, ?int $limit = null): ReportTable
    {
        $rows = match ($name) {
            'summary' => $this->summaryRows($range),
            'daily' => $this->daily($range),
            'pages' => $this->pages($range, $limit),
            'sources' => $this->sources($range, $limit),
            'languages' => $this->languages($range),
            'devices' => $this->devices($range),
            'searches' => $this->searches($range, $limit),
            'searches-without-results' => $this->searchesWithoutResults($range, $limit),
            'not-found' => $this->notFound($range, $limit),
            'bots' => $this->bots($range, $limit),
            default => throw new InvalidArgumentException(sprintf('There is no "%s" report. The reports are: %s.', $name, implode(', ', self::TABLES))),
        };
        $columns = match ($name) {
            'summary' => ['figure', 'current', 'previous', 'change_percent'],
            'daily' => ['day', 'visitors', 'views'],
            'pages' => ['page_id', 'locale', 'title', 'path', 'views', 'visitors'],
            'sources' => ['type', 'source', 'visitors', 'views'],
            'languages' => ['locale', 'visitors', 'views'],
            'devices' => ['device', 'visitors', 'views'],
            'searches' => ['query', 'searches', 'without_results'],
            'searches-without-results' => ['query', 'searches'],
            'not-found' => ['path', 'requests'],
            default => ['bot', 'kind', 'resource', 'reads'],
        };

        return new ReportTable($name, $range, $columns, $rows);
    }

    /**
     * @return list<array{figure: string, current: int|float, previous: int|float, change_percent: float|null}>
     */
    private function summaryRows(DateRange $range): array
    {
        $summary = $this->summary($range);
        $rows = [];
        foreach (['visitors' => $summary->visitors, 'views' => $summary->views, 'views_per_visitor' => $summary->viewsPerVisitor] as $name => $figure) {
            $rows[] = ['figure' => $name, 'current' => $figure->current, 'previous' => $figure->previous, 'change_percent' => $figure->change()];
        }

        return $rows;
    }

    /**
     * @return array{visitors: int, views: int}
     */
    private function totals(DateRange $range): array
    {
        $visitors = 0;
        $views = 0;
        foreach ($this->figures([Metric::VISITORS, Metric::VIEWS], Metric::TOTAL, $range) as $figure) {
            if ($figure['metric'] === Metric::VISITORS) {
                $visitors += $figure['value'];
            } else {
                $views += $figure['value'];
            }
        }

        return ['visitors' => $visitors, 'views' => $views];
    }

    /**
     * @param array{visitors: int, views: int} $totals
     */
    private static function perVisitor(array $totals): float
    {
        return $totals[Metric::VISITORS] === 0 ? 0.0 : round($totals[Metric::VIEWS] / $totals[Metric::VISITORS], 2);
    }

    /**
     * Views and visitors per item of a dimension, most views (or visitors) first.
     *
     * @return array<string, array{views: int, visitors: int}>
     */
    private function ranked(string $dimension, DateRange $range, ?int $limit = null, string $by = Metric::VIEWS): array
    {
        $ranked = [];
        foreach ($this->sum([Metric::VIEWS, Metric::VISITORS], $dimension, $range, $by, $limit) as $item => $values) {
            $ranked[(string) $item] = ['views' => $values[Metric::VIEWS] ?? 0, 'visitors' => $values[Metric::VISITORS] ?? 0];
        }

        return $ranked;
    }

    /**
     * The values of some metrics per item of a dimension, summed over the range, sorted by `$by` (most first, then
     * by item) and cut to `$limit` items.
     *
     * @param list<string> $metrics
     *
     * @return array<string, array<string, int>> item => metric => value (0 when it has none)
     */
    private function sum(array $metrics, string $dimension, DateRange $range, string $by, ?int $limit): array
    {
        $sums = [];
        foreach ($this->figures($metrics, $dimension, $range) as $figure) {
            $sums[$figure['item']] ??= array_fill_keys($metrics, 0);
            $sums[$figure['item']][$figure['metric']] += $figure['value'];
        }
        $items = array_map(strval(...), array_keys($sums));
        usort($items, static fn (string $a, string $b): int => [$sums[$b][$by], $a] <=> [$sums[$a][$by], $b]);
        $sorted = [];
        foreach ($limit === null ? $items : array_slice($items, 0, max(0, $limit)) as $item) {
            $sorted[$item] = $sums[$item];
        }

        return $sorted;
    }

    /**
     * Stored figures of the range plus the live figures of its days that aren't rolled up yet.
     *
     * @param list<string> $metrics
     *
     * @return list<array{day: string, metric: string, item: string, value: int}>
     */
    private function figures(array $metrics, string $dimension, DateRange $range): array
    {
        $figures = $this->store->figures($metrics, $dimension, $range->from, $range->to);
        foreach ($this->live() as $day => $rows) {
            if (!$range->contains($day)) {
                continue;
            }
            foreach ($rows as $row) {
                if ($row['dimension'] === $dimension && in_array($row['metric'], $metrics, true)) {
                    $figures[] = ['day' => $day, 'metric' => $row['metric'], 'item' => $row['item'], 'value' => $row['value']];
                }
            }
        }

        return $figures;
    }

    /**
     * @return array<string, list<array{metric: string, dimension: string, item: string, value: int}>>
     */
    private function live(): array
    {
        if ($this->live === null) {
            $this->live = [];
            $tomorrow = new DateTimeImmutable($this->today)->modify('+1 day')->format('Y-m-d');
            foreach ($this->store->unfinishedDaysBefore($tomorrow) as $day) {
                $this->live[$day] = $this->aggregator->aggregate($this->store->hits($day));
            }
        }

        return $this->live;
    }
}
