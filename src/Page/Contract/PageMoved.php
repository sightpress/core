<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Dispatched after a page got a new parent or position. Its addresses, and those of every page below it, may have
 * changed with it.
 */
final readonly class PageMoved
{
    public function __construct(
        public int $pageId,
        public ?int $fromParentId,
        public ?int $toParentId,
        public int $position,
        public int $revision,
        public ?int $movedBy,
    ) {
    }
}
