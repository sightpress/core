<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Page\Contract\PageLayouts;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\Site;

/**
 * {@see PageLayouts} from the page tree.
 */
final readonly class PageLayoutQuery implements PageLayouts
{
    public function __construct(
        private PageRepository $pages,
        private Site $site,
    ) {
    }

    public function pagesNotIn(array $layouts): array
    {
        $found = [];
        foreach ($this->pages->all() as $page) {
            if ($page->state() === PageState::Trash || in_array($page->layout(), $layouts, true)) {
                continue;
            }
            $translation = $page->translation($this->site->defaultLocale) ?? (array_values($page->translations())[0] ?? null);
            $found[] = ['id' => (int) $page->id(), 'title' => $translation?->title() ?? '#' . $page->id(), 'layout' => $page->layout()];
        }

        return $found;
    }
}
