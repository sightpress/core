<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * A page as a link: its title in one locale, its address on the site (`/about`, `/pt-pt/sobre`, `/` for the home
 * page), its depth in the tree (0 at the top) and whether everyone may read it (published and public).
 */
final readonly class PageLink
{
    public function __construct(
        public int $id,
        public string $title,
        public string $url,
        public int $depth,
        public bool $public,
    ) {
    }
}
