<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Block\Contract\MediaTexts;
use SightPress\Core\Page\Contract\PageText;
use SightPress\Core\Page\Contract\PageTexts;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Tenant\Site;

/**
 * Reads a page's saved translation as the person who may edit it sees it: the blocks as Markdown, with the media's
 * alt text in the page's language.
 */
final readonly class PageTextQuery implements PageTexts
{
    public function __construct(
        private PageRepository $pages,
        private Authorizer $authorizer,
        private BlockRenderer $renderer,
        private BlockContexts $contexts,
        private MediaTexts $texts,
        private Site $site,
    ) {
    }

    public function forEditing(Actor $actor, int $pageId, string $locale): ?PageText
    {
        $page = $this->pages->find($pageId);
        if ($page === null || !$this->authorizer->can($actor, PageAction::Edit->value, $page)) {
            return null;
        }
        $translation = $page->translation($locale);
        if ($translation === null) {
            return null;
        }
        $blocks = $this->texts->fill($translation->blocks(), $locale);
        $markdown = $this->renderer->markdown($blocks, $this->contexts->create($locale, 'https://' . ($this->site->domains[0] ?? 'localhost')));

        return new PageText($pageId, $locale, $translation->title(), $translation->summary() ?? '', trim($markdown));
    }
}
