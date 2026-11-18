<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use DateTimeImmutable;

/**
 * Dispatched after someone set or cleared when a page is published and taken off the site (both null: cleared).
 * Times are UTC.
 */
final readonly class PageScheduled
{
    public function __construct(
        public int $pageId,
        public ?DateTimeImmutable $publishAt,
        public ?DateTimeImmutable $unpublishAt,
        public ?int $actorId,
    ) {
    }
}
