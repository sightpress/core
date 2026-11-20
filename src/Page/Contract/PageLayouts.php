<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * The pages of the site by layout, for the theme picker (T-3): before a theme without some layouts is applied, it
 * lists the pages that will be shown in the standard layout instead.
 */
interface PageLayouts
{
    /**
     * Pages, in every state but the trash, whose layout isn't one of these, in the site's order.
     *
     * @param list<string> $layouts layout ids a theme provides
     *
     * @return list<array{id: int, title: string, layout: string}> the title in the site's main language (or the first
     *                                                            one the page has)
     */
    public function pagesNotIn(array $layouts): array;
}
