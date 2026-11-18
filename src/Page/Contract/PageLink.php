<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * A page as a link: its title in one locale, its address on the site (`/about`, `/pt-pt/sobre`, `/` for the home
 * page), its depth in the tree (0 at the top), whether everyone may read it (published and public, on a public site),
 * whether search engines may list it (also not `noindex` and not a copy of another page): only those are `hreflang`
 * alternates — and whether it is published in that locale at all (members-only pages too), for signed-in readers.
 */
final readonly class PageLink
{
    public function __construct(
        public int $id,
        public string $title,
        public string $url,
        public int $depth,
        public bool $public,
        public bool $indexable = false,
        public bool $published = false,
    ) {
    }
}
