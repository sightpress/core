<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use DateTimeImmutable;
use SightPress\Core\Rbac\Contract\PageState;

/**
 * A page in a list: its title in one language, its workflow state and when it last changed (UTC).
 */
final readonly class PageSummary
{
    public function __construct(
        public int $id,
        public string $locale,
        public string $title,
        public PageState $state,
        public ?int $authorId,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
