<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Dispatched after a translation of a page was published on its own, or made a draft again, and the change was
 * stored: for the audit log, cache purges, sitemaps and search.
 */
final readonly class TranslationPublished
{
    /**
     * @param bool $published true when visitors can now read it (once its page is published), false when it is a
     *                        draft again
     */
    public function __construct(
        public int $pageId,
        public string $locale,
        public bool $published,
        public int $revision,
        public ?int $actorId,
    ) {
    }
}
