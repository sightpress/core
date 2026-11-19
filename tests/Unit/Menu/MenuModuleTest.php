<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Menu;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Menu\MenuMigrations;
use SightPress\Core\Menu\MenuModule;
use SightPress\Core\Menu\PublicMenus;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Page\Contract\PublicPageRendering;

#[CoversClass(MenuModule::class)]
#[CoversClass(MenuMigrations::class)]
#[UsesNamespace('SightPress\Core')]
final class MenuModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersItsTableEditorListenerAndScreen(): void
    {
        $module = new MenuModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        );

        self::assertSame('menu', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['site', 'rbac', 'page', 'admin'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        $migrations = array_values(array_filter($registry->migrations(), static fn ($m): bool => $m->module === 'menu'));
        self::assertSame('SightPress\Core\Menu\Migrations', $migrations[0]->item->namespace());
        self::assertDirectoryExists($migrations[0]->item->directory());
        self::assertTrue($registry->services()[MenuEditor::class]->item->public);
        $listeners = array_values(array_filter($registry->listeners(), static fn ($l) => $l->module === 'menu'));
        self::assertSame([PublicPageRendering::class, PublicMenus::class], [$listeners[0]->item->event, $listeners[0]->item->listener]);
        $last = end($listeners);
        self::assertNotFalse($last);
        self::assertSame([\SightPress\Core\Page\Contract\PublicScreenRendering::class, PublicMenus::class], [$last->item->event, $last->item->listener]);
        self::assertSame([\SightPress\Core\Site\Contract\SiteProvisioning::class, \SightPress\Core\Menu\Provisioning\StarterMenus::class, -10], [$listeners[1]->item->event, $listeners[1]->item->listener, $listeners[1]->item->priority]);
        $routes = array_values(array_filter($registry->routes(), static fn ($r) => $r->module === 'menu'));
        self::assertSame('/admin/menus', $routes[0]->item->path);
        $menu = array_values(array_filter($registry->adminMenu(), static fn ($m) => $m->module === 'menu'));
        self::assertSame('menu.manage', $menu[0]->item->permission);
        $views = array_values(array_filter($registry->views(), static fn ($v) => $v->module === 'menu'));
        self::assertFileExists($views[0]->item->directory . '/admin.html.twig');
    }
}
