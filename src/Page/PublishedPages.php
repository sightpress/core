<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Page\Contract\PublicPage;
use SightPress\Core\Page\Contract\PublicPages;

/**
 * Finds published, public pages by their address ({@see PageAddresses}): `/` is the home page. A translation that
 * is a draft of its own isn't found.
 */
final readonly class PublishedPages implements PublicPages
{
    public function __construct(private PageAddresses $addresses)
    {
    }

    public function findByUrl(string $url): ?PublicPage
    {
        $translation = $this->addresses->find($url);
        if ($translation === null) {
            return null;
        }
        $page = $translation->page();
        if (!$translation->isPublished() || !$page->isPublic()) {
            return null;
        }

        return new PublicPage(
            (int) $page->id(),
            $translation->locale(),
            $this->addresses->url($translation),
            $translation->title(),
            $translation->blocks(),
        );
    }
}
