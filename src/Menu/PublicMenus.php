<?php

declare(strict_types=1);

namespace SightPress\Core\Menu;

use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Page\Contract\PageLink;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Tenant\Site;

/**
 * Puts the menus and the breadcrumbs on every page of the public site (three queries: the two menus, then every
 * page they and the breadcrumbs need). A menu entry for a page shows the page's title in the visitor's language (or
 * its own label); entries for pages that aren't published and public, or not written in that language, are left
 * out with the entries under them. The entry for the page being read is marked `current`.
 */
final readonly class PublicMenus
{
    public function __construct(
        private MenuEditor $menus,
        private PageLinks $pages,
        private Site $site,
    ) {
    }

    public function __invoke(PublicPageRendering $event): void
    {
        $main = $this->menus->tree(MenuName::Main);
        $footer = $this->menus->tree(MenuName::Footer);
        $links = $this->pages->find([...MenuNode::pageIds($main), ...MenuNode::pageIds($footer), ...$event->ancestorIds], $event->locale);

        $event->set('main_menu', $this->entries($main, $links, $event->url));
        $event->set('footer_menu', $this->entries($footer, $links, $event->url));
        if (!$event->home) {
            $crumbs = [['label' => null, 'url' => $event->locale === $this->site->defaultLocale ? '/' : '/' . strtolower($event->locale)]];
            foreach ($event->ancestorIds as $id) {
                if (isset($links[$id]) && $links[$id]->public) {
                    $crumbs[] = ['label' => $links[$id]->title, 'url' => $links[$id]->url];
                }
            }
            $crumbs[] = ['label' => $event->title, 'url' => $event->url];
            $event->set('breadcrumbs', $crumbs);
        }
    }

    /**
     * @param list<MenuNode>       $nodes
     * @param array<int, PageLink> $links
     *
     * @return list<array<string, mixed>> {label, url, current, children}
     */
    private function entries(array $nodes, array $links, string $current): array
    {
        $entries = [];
        foreach ($nodes as $node) {
            $item = $node->item;
            $pageId = $item->pageId();
            if ($pageId !== null) {
                $link = $links[$pageId] ?? null;
                if ($link === null || !$link->public) {
                    continue;
                }
                [$label, $url] = [$item->label() ?? $link->title, $link->url];
            } else {
                [$label, $url] = [(string) $item->label(), (string) $item->url()];
            }
            $entries[] = ['label' => $label, 'url' => $url, 'current' => $url === $current, 'children' => $this->entries($node->children, $links, $current)];
        }

        return $entries;
    }
}
