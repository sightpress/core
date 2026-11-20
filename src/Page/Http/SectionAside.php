<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Http;

use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Template\TemplateRenderer;

/**
 * The side column of a page in the `sidebar` layout (T-3): "In this section", a list of links to the page above it
 * and the pages under it that the visitor may read in the page's language, rendered by `@page/aside.html.twig` into
 * the `aside` variable, which the layout's `aside` block prints. Nothing for other layouts or when there is no such
 * page.
 */
final readonly class SectionAside
{
    public const string LAYOUT = 'sidebar';

    public const string VARIABLE = 'aside';

    public function __construct(
        private PageRepository $pages,
        private PageAddresses $addresses,
        private Authorizer $authorizer,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(PublicPageRendering $event): void
    {
        $page = $this->pages->find($event->pageId);
        if ($page === null || $page->layout() !== self::LAYOUT) {
            return;
        }
        $actor = Actor::fromRequest($event->request);
        $link = function (?Page $page) use ($event, $actor): ?array {
            $translation = $page?->translation($event->locale);
            if ($page === null || $translation === null || !$translation->isPublished() || !$this->authorizer->can($actor, PageAction::View->value, $page)) {
                return null;
            }

            return ['title' => $translation->title(), 'url' => $this->addresses->url($translation)];
        };
        $children = array_values(array_filter(array_map($link, $this->pages->children($page))));
        $parent = $link($page->parent());
        if ($parent === null && $children === []) {
            return;
        }
        $event->set(self::VARIABLE, $this->templates->render($event->request, '@page/aside.html.twig', [
            'parent' => $parent,
            'children' => $children,
            'title' => $event->title,
        ]));
    }
}
