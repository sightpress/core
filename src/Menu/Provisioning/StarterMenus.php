<?php

declare(strict_types=1);

namespace SightPress\Core\Menu\Provisioning;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Site\Contract\SiteProvisioning;

/**
 * New site, step {@see PRIORITY} (after the starter pages): puts About and Contact in the main menu and Privacy,
 * Terms and the Accessibility statement in the footer menu, when those pages exist and the menus are still empty.
 */
final readonly class StarterMenus
{
    public const int PRIORITY = SiteProvisioning::CONTENT - 10;

    /** Page addresses in the site's main language, by menu. */
    private const array PAGES = [
        'main' => ['/about', '/contact'],
        'footer' => ['/privacy', '/terms', '/accessibility'],
    ];

    public function __invoke(SiteProvisioning $event): void
    {
        $menus = Services::get($event->services, MenuEditor::class);
        $ids = [];
        foreach (Services::get($event->services, PageLinks::class)->all($event->site->defaultLocale) as $link) {
            $ids[$link->url] = $link->id;
        }
        $added = 0;
        foreach (self::PAGES as $menu => $urls) {
            $name = MenuName::from($menu);
            if ($menus->items($name) !== []) {
                continue;
            }
            foreach ($urls as $url) {
                if (isset($ids[$url])) {
                    $menus->add($name, $ids[$url], actorId: $event->adminId());
                    ++$added;
                }
            }
        }
        if ($added > 0) {
            $event->report(sprintf('Added %d starter pages to the main and footer menus.', $added));
        }
    }
}
