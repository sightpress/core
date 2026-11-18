<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Dispatched after a page in the trash was deleted for good, with its revisions and the pages under it (which were
 * in the trash too). Its addresses now answer "gone" (410).
 */
final readonly class PagePurged
{
    /**
     * @param string    $title   the page's title in the site's main language (or its first one), as it was
     * @param list<int> $pageIds the page and every page that was under it
     */
    public function __construct(
        public int $pageId,
        public string $title,
        public array $pageIds,
        public ?int $purgedBy,
    ) {
    }
}
