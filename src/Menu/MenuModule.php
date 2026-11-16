<?php

declare(strict_types=1);

namespace SightPress\Core\Menu;

use SightPress\Core\Menu\Http\MenuAdminHandler;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * Menus: the `menu_items` table with the site's main and footer menus, the {@see MenuEditor} that changes them, the
 * menu editor in the admin (`/admin/menus`, permission `menu.manage`), and {@see PublicMenus}, which puts the menus
 * and the breadcrumbs on every page of the public site. Always on.
 */
final class MenuModule implements ModuleInterface
{
    public const string ID = 'menu';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [
                new ModuleRequirement('site', '^0.1'),
                new ModuleRequirement('rbac', '^0.1'),
                new ModuleRequirement('page', '^0.1'),
                new ModuleRequirement('admin', '^0.1'),
            ],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new MenuMigrations());
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/menu');

        $services = $registrar->services();
        $services->autowire(MenuEditor::class, public: true);
        $services->autowire(PublicMenus::class);
        $services->autowire(MenuAdminHandler::class);

        $registrar->subscribers()->listen(PublicPageRendering::class, PublicMenus::class);
        $registrar->routes()->add(new Route('menu.admin', MenuAdminHandler::PATH, MenuAdminHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('menu.admin', 'Menus', 'menu.admin', MenuAdminHandler::PERMISSION, position: 35));
    }
}
