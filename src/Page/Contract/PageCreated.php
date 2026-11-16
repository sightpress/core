<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Dispatched after a new page was saved with its first translation and revision.
 */
final readonly class PageCreated
{
    public function __construct(
        public int $pageId,
        public string $locale,
        public ?int $authorId,
    ) {
    }
}
