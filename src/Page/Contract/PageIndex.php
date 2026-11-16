<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Every published page in every language, for sitemaps, `llms.txt` and SEO checks; one query.
 */
interface PageIndex
{
    /**
     * @return list<IndexedPage> in tree order, each language of a page together, the site's default locale first
     */
    public function published(): array;
}
