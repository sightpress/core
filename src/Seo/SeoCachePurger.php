<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

/**
 * Forgets the cached sitemap and `robots.txt` whenever a page is created, saved, moved, published or unpublished.
 */
final readonly class SeoCachePurger
{
    public function __construct(private SeoFiles $files)
    {
    }

    public function __invoke(object $event): void
    {
        $this->files->purge();
    }
}
