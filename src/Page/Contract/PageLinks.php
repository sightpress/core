<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Pages as links, for menus and breadcrumbs. Each call is one query, whatever the number of pages.
 */
interface PageLinks
{
    /**
     * The pages with these ids that are written in the locale, keyed by id.
     *
     * @param list<int> $ids
     *
     * @return array<int, PageLink>
     */
    public function find(array $ids, string $locale): array;

    /**
     * Every page written in the locale, in tree order (each page right after its parent), whatever its state.
     *
     * @return list<PageLink>
     */
    public function all(string $locale): array;
}
