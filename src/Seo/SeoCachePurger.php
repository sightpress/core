<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

/**
 * Forgets the cached sitemap, `llms.txt` and `robots.txt` whenever a page is created, saved, moved, published,
 * unpublished, archived, put in or taken out of the trash, or deleted for good (also a translation on its own), and
 * when the site settings change (its languages, its name).
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
