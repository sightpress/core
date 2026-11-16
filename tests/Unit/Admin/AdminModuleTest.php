<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Admin\AdminModule;
use SightPress\Core\Admin\AdminShell;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Admin\Http\DashboardHandler;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;

#[CoversClass(AdminModule::class)]
#[UsesNamespace('SightPress\Core')]
final class AdminModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersTheShellAndTheDashboard(): void
    {
        $module = new AdminModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        );

        self::assertSame('admin', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['site', 'user', 'rbac', 'page'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        self::assertSame(AdminShell::class, $registry->services()[AdminScreens::class]->item->class);
        self::assertTrue($registry->services()[AdminScreens::class]->item->public);
        $routes = array_values(array_filter($registry->routes(), static fn ($r) => $r->module === 'admin'));
        self::assertSame('/admin', $routes[0]->item->path);
        self::assertSame(DashboardHandler::class, $routes[0]->item->handler);
        self::assertSame([AdminAccessMiddleware::class], $routes[0]->item->middleware);
        $menu = array_values(array_filter($registry->adminMenu(), static fn ($m) => $m->module === 'admin'));
        self::assertSame(['dashboard', 'admin.dashboard', null], [$menu[0]->item->id, $menu[0]->item->route, $menu[0]->item->permission]);
        $views = array_values(array_filter($registry->views(), static fn ($v) => $v->module === 'admin'));
        self::assertFileExists($views[0]->item->directory . '/layout.html.twig');
    }
}
