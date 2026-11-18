<?php

declare(strict_types=1);

namespace SightPress\Core\Menu;

use SightPress\Core\Page\Contract\PagePurged;

/**
 * When pages are deleted for good, their menu entries go too (the entries under them move up), so no menu keeps an
 * entry for a page that is gone.
 */
final readonly class ForgetPurgedPages
{
    public function __construct(private MenuEditor $menus)
    {
    }

    public function __invoke(PagePurged $event): void
    {
        $this->menus->forgetPages($event->pageIds, $event->purgedBy);
    }
}
