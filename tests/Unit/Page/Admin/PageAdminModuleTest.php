<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Page\Admin\Editor\BlockData;
use SightPress\Core\Page\Admin\Editor\BlockFormView;
use SightPress\Core\Page\Admin\Editor\BlockOperation;
use SightPress\Core\Page\Admin\Editor\MediaChoices;
use SightPress\Core\Page\Admin\Editor\PageEditorScreen;
use SightPress\Core\Page\Admin\Http\NewPageHandler;
use SightPress\Core\Page\Admin\Http\PageEditorHandler;
use SightPress\Core\Page\Admin\Http\PageListHandler;
use SightPress\Core\Page\Admin\Http\PageWorkflowHandler;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Admin\PageAdminModule;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;

#[CoversClass(PageAdminModule::class)]
#[UsesNamespace('SightPress\Core')]
final class PageAdminModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersThePageScreens(): void
    {
        $module = new PageAdminModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        );

        self::assertSame('page_admin', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['site', 'user', 'rbac', 'media', 'block', 'page', 'admin'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        foreach ([
            PageAdmin::class, BlockData::class, BlockOperation::class, BlockFormView::class, MediaChoices::class,
            PageEditorScreen::class, PageListHandler::class, NewPageHandler::class, PageEditorHandler::class, PageWorkflowHandler::class,
        ] as $class) {
            self::assertArrayHasKey($class, $registry->services());
        }
        $routes = [];
        foreach (array_filter($registry->routes(), static fn ($r) => $r->module === 'page_admin') as $route) {
            $routes[$route->item->name] = [$route->item->path, $route->item->handler, $route->item->methods, $route->item->middleware];
        }
        $admin = [AdminAccessMiddleware::class];
        self::assertSame([
            'page_admin.list' => ['/admin/pages', PageListHandler::class, ['GET'], $admin],
            'page_admin.new' => ['/admin/pages/new', NewPageHandler::class, ['GET', 'POST'], $admin],
            'page_admin.edit' => ['/admin/pages/{id:[0-9]+}', PageEditorHandler::class, ['GET', 'POST'], $admin],
            'page_admin.workflow' => ['/admin/pages/{id:[0-9]+}/workflow', PageWorkflowHandler::class, ['POST'], $admin],
        ], $routes);
        $menu = array_values(array_filter($registry->adminMenu(), static fn ($m) => $m->module === 'page_admin'));
        self::assertSame(['page_admin.list', 'Pages', 'page.edit.own', 10], [$menu[0]->item->id, $menu[0]->item->label, $menu[0]->item->permission, $menu[0]->item->position]);
        $views = array_values(array_filter($registry->views(), static fn ($v) => $v->module === 'page_admin'));
        self::assertFileExists($views[0]->item->directory . '/editor.html.twig');
    }
}
