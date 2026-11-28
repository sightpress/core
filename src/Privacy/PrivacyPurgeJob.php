<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\ScheduleDue;

/**
 * The privacy module's job in `schedule:run` (GD-2): {@see PrivacyPurge} once a day for each site.
 */
final readonly class PrivacyPurgeJob
{
    public function __invoke(ScheduleDue $event): void
    {
        $purge = Services::get($event->services, PrivacyPurge::class);
        if (!$purge->isDue($event->now)) {
            return;
        }
        $removed = array_sum($purge->run($event->now));
        if ($removed > 0) {
            $event->report(sprintf('Privacy: deleted %d %s older than the site keeps them.', $removed, $removed === 1 ? 'record' : 'records'));
        }
    }
}
