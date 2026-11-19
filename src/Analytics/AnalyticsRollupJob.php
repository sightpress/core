<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\ScheduleDue;

/**
 * Part of `schedule:run`: once an hour, rolls up the site's finished analytics days and deletes figures older than
 * the retention ({@see AnalyticsRollup}).
 */
final readonly class AnalyticsRollupJob
{
    public function __invoke(ScheduleDue $event): void
    {
        $rollup = Services::get($event->services, AnalyticsRollup::class);
        if (!$rollup->isDue($event->now)) {
            return;
        }
        ['days' => $days, 'purged' => $purged] = $rollup->run($event->now);
        if ($days !== []) {
            $event->report(sprintf('Analytics: rolled up %s.', implode(', ', $days)));
        }
        if ($purged > 0) {
            $event->report(sprintf('Analytics: deleted %d daily %s older than the retention.', $purged, $purged === 1 ? 'figure' : 'figures'));
        }
    }
}
