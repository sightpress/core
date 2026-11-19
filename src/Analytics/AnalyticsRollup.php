<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\Site;

/**
 * The hourly roll-up (ADR-0012), run by `schedule:run` ({@see AnalyticsRollupJob}): every finished day (before today
 * in the site's time zone) that still has raw hits becomes daily figures, and its raw hits and salt are deleted, so
 * its visitor keys can never be linked or reversed; then figures older than the retention are deleted. Safe to run
 * again: a day is written whole, in place of what it had.
 */
final readonly class AnalyticsRollup
{
    /** When the roll-up last ran (UTC, ATOM). */
    public const string LAST_RUN = 'analytics.rolled_up_at';

    /** Seconds between two roll-ups. */
    public const int EVERY = 3600;

    public function __construct(
        private AnalyticsStore $store,
        private DailyAggregator $aggregator,
        private AnalyticsRetention $retention,
        private SiteSettings $settings,
        private Site $site,
    ) {
    }

    public function today(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone($this->site->defaultTimezone))->format('Y-m-d');
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        $last = $this->lastRun();

        return $last === null || $now->getTimestamp() - $last->getTimestamp() >= self::EVERY;
    }

    public function lastRun(): ?DateTimeImmutable
    {
        $stored = $this->settings->get(self::LAST_RUN);
        $last = $stored === null ? false : DateTimeImmutable::createFromFormat(DATE_ATOM, $stored);

        return $last === false ? null : $last;
    }

    /**
     * Rolls up the finished days and deletes what is older than the retention.
     *
     * @return array{days: list<string>, purged: int} the days rolled up and the number of daily figures deleted
     */
    public function run(DateTimeImmutable $now): array
    {
        $today = $this->today($now);
        $days = $this->store->unfinishedDaysBefore($today);
        foreach ($days as $day) {
            $this->store->finishDay($day, self::merge($this->store->dayFigures($day), $this->aggregator->aggregate($this->store->hits($day))));
        }
        $purged = $this->store->deleteBefore($this->retention->firstKeptDay($today));
        $this->settings->set(self::LAST_RUN, $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));

        return ['days' => $days, 'purged' => $purged];
    }

    /**
     * A day's figures with those of raw hits that arrived after it was rolled up (a request answered just after
     * midnight) added to them, so a late hit never replaces the day.
     *
     * @param list<array{metric: string, dimension: string, item: string, value: int}> $stored
     * @param list<array{metric: string, dimension: string, item: string, value: int}> $new
     *
     * @return list<array{metric: string, dimension: string, item: string, value: int}>
     */
    private static function merge(array $stored, array $new): array
    {
        $figures = [];
        foreach ([...$stored, ...$new] as $figure) {
            $key = $figure['metric'] . "\n" . $figure['dimension'] . "\n" . $figure['item'];
            if (isset($figures[$key])) {
                $figures[$key]['value'] += $figure['value'];
            } else {
                $figures[$key] = $figure;
            }
        }

        return array_values($figures);
    }
}
