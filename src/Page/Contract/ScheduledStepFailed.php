<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Dispatched when `schedule:run` couldn't publish (or unpublish) a page at its time, for example because the publish
 * checks found errors or the person who scheduled it may no longer publish. That part of the schedule is cleared.
 */
final readonly class ScheduledStepFailed
{
    /**
     * @param string $reason in en-GB, for the audit log
     */
    public function __construct(
        public int $pageId,
        public PageTransition $transition,
        public string $reason,
        public ?int $scheduledBy,
    ) {
    }
}
