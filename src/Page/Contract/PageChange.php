<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use DateTimeImmutable;

/**
 * One save of a page: which page, who saved it (null for the system, such as the console) and when (UTC).
 */
final readonly class PageChange
{
    public function __construct(
        public PageSummary $page,
        public int $revision,
        public ?int $savedBy,
        public DateTimeImmutable $savedAt,
    ) {
    }
}
