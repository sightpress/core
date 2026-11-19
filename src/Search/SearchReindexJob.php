<?php

declare(strict_types=1);

namespace SightPress\Core\Search;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\ScheduleDue;

/**
 * Part of `schedule:run`: builds a site's search index when it was never built, or a change to the whole site made
 * it out of date ({@see SearchIndex::markOutOfDate()}).
 */
final readonly class SearchReindexJob
{
    public function __invoke(ScheduleDue $event): void
    {
        $index = Services::get($event->services, SearchIndex::class);
        if ($index->isReady()) {
            return;
        }
        $counts = $index->reindex();
        $total = array_sum($counts);
        $event->report(sprintf('Site search: indexed %d %s.', $total, $total === 1 ? 'document' : 'documents'));
    }
}
