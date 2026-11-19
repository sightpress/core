<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * What site search needs to know about pages besides their documents (SR-1): which pages a change touches. Pages
 * are documents of the source {@see self::SOURCE}, with the page id as ref.
 */
interface SearchablePages
{
    public const string SOURCE = 'page';

    /**
     * The page and every page below it, whose addresses and breadcrumbs follow it.
     *
     * @return list<int> the page first, then the pages below it top-down; empty when there is no such page
     */
    public function withPagesBelow(int $pageId): array;

    /**
     * The published pages whose blocks show a file of the media library, whose alt text, caption or transcript are
     * part of their text.
     *
     * @return list<int>
     */
    public function usingMedia(int $mediaId): array;
}
