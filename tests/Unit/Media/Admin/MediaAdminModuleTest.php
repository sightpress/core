<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Admin\Http\DirectUploadHandler;
use SightPress\Core\Media\Admin\Http\MediaDeleteHandler;
use SightPress\Core\Media\Admin\Http\MediaItemHandler;
use SightPress\Core\Media\Admin\Http\MediaLibraryHandler;
use SightPress\Core\Media\Admin\Http\MediaUploadHandler;
use SightPress\Core\Media\Admin\Http\TusUploadHandler;
use SightPress\Core\Media\Admin\MediaAdmin;
use SightPress\Core\Media\Admin\MediaAdminModule;
use SightPress\Core\Media\MediaEditor;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;

#[CoversClass(MediaAdminModule::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaAdminModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersTheLibraryScreens(): void
    {
        $module = new MediaAdminModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        );

        self::assertSame('media_admin', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['site', 'rbac', 'media', 'admin'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        foreach ([MediaAdmin::class, MediaEditor::class, MediaLibraryHandler::class, MediaUploadHandler::class, TusUploadHandler::class, MediaItemHandler::class, MediaDeleteHandler::class] as $class) {
            self::assertArrayHasKey($class, $registry->services());
        }
        $routes = [];
        foreach (array_filter($registry->routes(), static fn ($r) => $r->module === 'media_admin') as $route) {
            $routes[$route->item->name] = [$route->item->path, $route->item->handler, $route->item->methods, $route->item->middleware];
        }
        $admin = [AdminAccessMiddleware::class];
        self::assertSame([
            'media_admin.library' => ['/admin/media', MediaLibraryHandler::class, ['GET'], $admin],
            'media_admin.upload' => ['/admin/media/upload', MediaUploadHandler::class, ['POST'], $admin],
            'media_admin.uploads' => ['/admin/media/uploads', TusUploadHandler::class, ['POST', 'OPTIONS'], $admin],
            'media_admin.uploads.session' => ['/admin/media/uploads/{upload:[0-9a-f]{32}}', TusUploadHandler::class, ['HEAD', 'PATCH', 'DELETE'], $admin],
            'media_admin.direct' => ['/admin/media/direct', DirectUploadHandler::class, ['POST'], $admin],
            'media_admin.direct.session' => ['/admin/media/direct/{upload:[0-9a-f]{32}}', DirectUploadHandler::class, ['GET', 'DELETE'], $admin],
            'media_admin.direct.urls' => ['/admin/media/direct/{upload:[0-9a-f]{32}}/urls', DirectUploadHandler::class, ['POST'], $admin],
            'media_admin.direct.complete' => ['/admin/media/direct/{upload:[0-9a-f]{32}}/complete', DirectUploadHandler::class, ['POST'], $admin],
            'media_admin.item' => ['/admin/media/{id:[0-9]+}', MediaItemHandler::class, ['GET', 'POST'], $admin],
            'media_admin.captions' => ['/admin/media/{id:[0-9]+}/captions', \SightPress\Core\Media\Admin\Http\MediaCaptionsHandler::class, ['POST'], $admin],
            'media_admin.delete' => ['/admin/media/{id:[0-9]+}/delete', MediaDeleteHandler::class, ['GET', 'POST'], $admin],
        ], $routes);
        $menu = array_values(array_filter($registry->adminMenu(), static fn ($m) => $m->module === 'media_admin'));
        self::assertSame(['media_admin.library', 'Media', 'media.upload', 30], [$menu[0]->item->id, $menu[0]->item->label, $menu[0]->item->permission, $menu[0]->item->position]);
        $views = array_values(array_filter($registry->views(), static fn ($v) => $v->module === 'media_admin'));
        self::assertFileExists($views[0]->item->directory . '/library.html.twig');
    }
}
