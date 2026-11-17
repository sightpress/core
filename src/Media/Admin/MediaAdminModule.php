<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin;

use SightPress\Core\Media\Admin\Http\MediaDeleteHandler;
use SightPress\Core\Media\Admin\Http\MediaItemHandler;
use SightPress\Core\Media\Admin\Http\MediaLibraryHandler;
use SightPress\Core\Media\Admin\Http\MediaUploadHandler;
use SightPress\Core\Media\MediaEditor;
use SightPress\Core\Media\Admin\Provisioning\DemoMedia;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * The media library in the admin: upload with announced progress, a grid or list of files, and each file's alt
 * text, caption, transcript and publish flag. A module of its own (in the media context) because the admin shell
 * depends on pages, which depend on blocks, which depend on media. Always on.
 */
final class MediaAdminModule implements ModuleInterface
{
    public const string ID = 'media_admin';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [
                new ModuleRequirement('site', '^0.1'),
                new ModuleRequirement('rbac', '^0.1'),
                new ModuleRequirement('media', '^0.1'),
                new ModuleRequirement('admin', '^0.1'),
            ],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->views()->add(self::ID, dirname(__DIR__, 3) . '/resources/views/media');

        $services = $registrar->services();
        foreach ([MediaAdmin::class, MediaEditor::class, MediaLibraryHandler::class, MediaUploadHandler::class, MediaItemHandler::class, MediaDeleteHandler::class, DemoMedia::class] as $class) {
            $services->autowire($class);
        }

        $item = MediaAdmin::PATH . '/{' . MediaAdmin::ID_ATTRIBUTE . ':[0-9]+}';
        $registrar->subscribers()->listen(SiteProvisioning::class, DemoMedia::class, DemoMedia::PRIORITY);
        $routes = $registrar->routes();
        $routes->add(new Route('media_admin.library', MediaAdmin::PATH, MediaLibraryHandler::class, middleware: [AdminAccessMiddleware::class]));
        $routes->add(new Route('media_admin.upload', MediaUploadHandler::PATH, MediaUploadHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('media_admin.item', $item, MediaItemHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('media_admin.delete', $item . '/delete', MediaDeleteHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('media_admin.library', 'Media', 'media_admin.library', MediaAdmin::UPLOAD, position: 30));
    }
}
