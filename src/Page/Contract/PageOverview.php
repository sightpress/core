<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Short lists of pages for the admin dashboard. Titles are in the site's default language (or the first language a
 * page has); newest first.
 */
interface PageOverview
{
    /**
     * Drafts the person started.
     *
     * @param positive-int $limit
     *
     * @return list<PageSummary>
     */
    public function draftsBy(int $authorId, int $limit): array;

    /**
     * Pages sent for review, waiting for an editor.
     *
     * @param positive-int $limit
     *
     * @return list<PageSummary>
     */
    public function awaitingReview(int $limit): array;

    /**
     * The latest saves of any page, one entry per save.
     *
     * @param positive-int $limit
     *
     * @return list<PageChange>
     */
    public function recentChanges(int $limit): array;
}
