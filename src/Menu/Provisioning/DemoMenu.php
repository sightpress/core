<?php

declare(strict_types=1);

namespace SightPress\Core\Menu\Provisioning;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Site\Contract\SiteProvisioning;

/**
 * Demo content, step 4 of 4: the demo page in the main menu, with the page under it one level down.
 */
final readonly class DemoMenu
{
    public const int PRIORITY = SiteProvisioning::DEMO - 30;

    public function __invoke(SiteProvisioning $event): void
    {
        $page = $event->shared('demo.page');
        if (!$event->demo || $page === null) {
            return;
        }
        $menus = Services::get($event->services, MenuEditor::class);
        $item = $menus->add(MenuName::Main, $page, actorId: $event->adminId());
        $subpage = $event->shared('demo.subpage');
        if ($subpage !== null) {
            $menus->add(MenuName::Main, $subpage, actorId: $event->adminId(), parent: $item);
        }
    }
}
