<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Module\Block\BlockContext;

/**
 * Makes the {@see BlockContext} blocks are rendered with on the current site.
 */
interface BlockContexts
{
    /**
     * @param string $locale  locale of the content, one of the site's
     * @param string                      $baseUrl scheme and host of the site, for example `https://example.org`
     * @param ServerRequestInterface|null $request the visitor's request when the page is rendered for one (blocks
     *                                             with forms need it); null for Markdown, feeds and checks
     */
    public function create(string $locale, string $baseUrl, ?ServerRequestInterface $request = null): BlockContext;
}
