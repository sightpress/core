<?php

declare(strict_types=1);

namespace SightPress\Core\Menu;

use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Page\Contract\MovedAddresses;
use SightPress\Core\Page\Contract\PageLink;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Page\Contract\PublicScreenRendering;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Site;

/**
 * Puts the menus and the breadcrumbs on every page of the public site, in the page's language (three queries: the
 * two menus, then every page they and the breadcrumbs need).
 *
 * A menu entry shows its own text in that language, else — for a page — the page's title in it (a link falls back to
 * its main text). An entry is left out, with the entries under it, when the visitor can't open what it leads to: a
 * page that isn't published in that language, a members-only page (or a page of a members-only site) for someone who
 * isn't signed in, an entry marked for members only, or a link to an address whose page was deleted. Links to an
 * address of the site whose page moved lead to its new address. The entry for the page being read is `current`
 * (`aria-current="page"`), in every language.
 */
final readonly class PublicMenus
{
    public function __construct(
        private MenuEditor $menus,
        private PageLinks $pages,
        private MovedAddresses $addresses,
        private Site $site,
    ) {
    }

    /**
     * The menus and, below the home page, the breadcrumb: on a page, the pages above it; on another public screen
     * (search results), the home page then the screen.
     */
    public function __invoke(PublicPageRendering|PublicScreenRendering $event): void
    {
        $signedIn = Actor::fromRequest($event->request)->isSignedIn();
        $ancestors = $event instanceof PublicPageRendering ? $event->ancestorIds : [];
        $main = $this->menus->tree(MenuName::Main);
        $footer = $this->menus->tree(MenuName::Footer);
        $links = $this->pages->find([...MenuNode::pageIds($main), ...MenuNode::pageIds($footer), ...$ancestors], $event->locale);
        $readable = array_filter($links, static fn (PageLink $link): bool => $link->public || ($signedIn && $link->published));

        $event->set('main_menu', $this->entries($main, $readable, $event->url, $event->locale, $signedIn));
        $event->set('footer_menu', $this->entries($footer, $readable, $event->url, $event->locale, $signedIn));
        if ($event instanceof PublicScreenRendering || !$event->home) {
            $crumbs = [['label' => null, 'url' => $event->locale === $this->site->defaultLocale ? '/' : '/' . strtolower($event->locale)]];
            foreach ($ancestors as $id) {
                if (isset($readable[$id])) {
                    $crumbs[] = ['label' => $readable[$id]->title, 'url' => $readable[$id]->url];
                }
            }
            $crumbs[] = ['label' => $event->title, 'url' => $event->url];
            $event->set('breadcrumbs', $crumbs);
        }
    }

    /**
     * @param list<MenuNode>       $nodes
     * @param array<int, PageLink> $links the pages the visitor may open, in the page's language
     *
     * @return list<array<string, mixed>> {label, url, current, children}
     */
    private function entries(array $nodes, array $links, string $current, string $locale, bool $signedIn): array
    {
        $entries = [];
        foreach ($nodes as $node) {
            $item = $node->item;
            if ($item->isMembersOnly() && !$signedIn) {
                continue;
            }
            $label = $item->labelIn($locale, $this->site->defaultLocale);
            $pageId = $item->pageId();
            if ($pageId !== null) {
                $link = $links[$pageId] ?? null;
                if ($link === null) {
                    continue;
                }
                [$label, $url] = [$label ?? $link->title, $link->url];
            } else {
                $url = $this->addresses->follow((string) $item->url());
                if ($url === null) {
                    continue;
                }
                $label = (string) $label;
            }
            $entries[] = ['label' => $label, 'url' => $url, 'current' => $url === $current, 'children' => $this->entries($node->children, $links, $current, $locale, $signedIn)];
        }

        return $entries;
    }
}
