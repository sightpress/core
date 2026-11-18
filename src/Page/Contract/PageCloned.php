<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Dispatched after a page was copied into a new draft page, with every translation (after {@see PageCreated}).
 */
final readonly class PageCloned
{
    public function __construct(
        public int $pageId,
        public int $sourceId,
        public ?int $clonedBy,
    ) {
    }
}
