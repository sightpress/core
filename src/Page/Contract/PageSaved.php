<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Dispatched after a page's content in one locale was saved as a new revision (also for a new page).
 */
final readonly class PageSaved
{
    public function __construct(
        public int $pageId,
        public string $locale,
        public int $revision,
        public ?int $savedBy,
    ) {
    }
}
