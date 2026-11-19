<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use SightPress\Module\Search\SearchPerformed;

/**
 * Hears each site search ({@see SearchPerformed}) and keeps what may be recorded of it — the query without personal
 * data ({@see QueryRedactor}), the language and the number of results — until the request has been answered.
 */
final readonly class SearchListener
{
    public function __construct(
        private PendingSearches $pending,
        private QueryRedactor $redactor,
    ) {
    }

    public function __invoke(SearchPerformed $event): void
    {
        $this->pending->add($this->redactor->redact($event->query), $event->locale, $event->resultCount);
    }
}
