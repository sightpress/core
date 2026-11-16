<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use SightPress\Module\Block\BlockContext;

/**
 * Makes the {@see BlockContext} blocks are rendered with on the current site.
 */
interface BlockContexts
{
    /**
     * @param string $locale  locale of the content, one of the site's
     * @param string $baseUrl scheme and host of the site, for example `https://example.org`
     */
    public function create(string $locale, string $baseUrl): BlockContext;
}
