<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Report;

use Psr\Clock\ClockInterface;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\DailyAggregator;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Tenant\Site;

/**
 * Opens the site's analytics for reading ({@see AnalyticsReport}): one source for the admin screen, the dashboard
 * widget, the CSV downloads and `site:analytics:report`.
 */
final readonly class AnalyticsReports
{
    public function __construct(
        private AnalyticsStore $store,
        private DailyAggregator $aggregator,
        private PageLinks $pages,
        private Site $site,
        private ClockInterface $clock,
    ) {
    }

    public function open(): AnalyticsReport
    {
        return new AnalyticsReport($this->store, $this->aggregator, $this->pages, $this->site, $this->clock->now());
    }
}
