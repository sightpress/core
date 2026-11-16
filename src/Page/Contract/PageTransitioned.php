<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use SightPress\Core\Rbac\Contract\PageState;

/**
 * Dispatched after a page moved through a workflow step and the change was stored: for the audit log,
 * notifications, cache purges, sitemaps and search.
 */
final readonly class PageTransitioned
{
    /**
     * @param string|null $comment what the person wrote with the step, for example why a page was sent back
     */
    public function __construct(
        public int $pageId,
        public PageTransition $transition,
        public PageState $from,
        public PageState $to,
        public ?int $actorId,
        public ?string $comment = null,
    ) {
    }
}
