<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Dispatched after an older version of a page in one language was restored as a new revision (after the
 * {@see PageSaved} of that revision). Restoring never changes the workflow state and never publishes.
 */
final readonly class RevisionRestored
{
    public function __construct(
        public int $pageId,
        public string $locale,
        public int $restored,
        public int $revision,
        public ?int $restoredBy,
    ) {
    }
}
