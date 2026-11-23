<?php

declare(strict_types=1);

namespace SightPress\Core\Admin;

use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Admin\Http\DashboardHandler;
use SightPress\Core\Admin\Http\LanguagesHandler;
use SightPress\Core\Admin\Http\RemoveLanguageHandler;
use SightPress\Core\Admin\Http\HideServerNoticeHandler;
use SightPress\Core\Admin\Http\SettingsHandler;
use SightPress\Core\Admin\Http\StorageHandler;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * The admin panel's shell (ADR-0004): the layout every `/admin` screen uses, with the navigation each person may
 * use, and the dashboard at `/admin`. Other modules render their screens through {@see AdminScreens}. Always on.
 */
final class AdminModule implements ModuleInterface
{
    public const string ID = 'admin';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [
                new ModuleRequirement('site', '^0.1'),
                new ModuleRequirement('auth', '^0.1'),
                new ModuleRequirement('user', '^0.1'),
                new ModuleRequirement('rbac', '^0.1'),
                new ModuleRequirement('page', '^0.1'),
            ],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/admin');
        $registrar->services()->autowire(AdminShell::class, AdminScreens::class, public: true);
        $registrar->services()->autowire(AdminShell::class);
        $registrar->services()->autowire(DashboardHandler::class);
        $registrar->services()->autowire(SettingsHandler::class);
        $registrar->services()->autowire(StorageHandler::class);
        $registrar->services()->autowire(HideServerNoticeHandler::class);
        $registrar->services()->autowire(LanguagesHandler::class);
        $registrar->services()->autowire(RemoveLanguageHandler::class);
        $registrar->routes()->add(new Route('admin.dashboard', '/admin', DashboardHandler::class, middleware: [AdminAccessMiddleware::class]));
        $registrar->routes()->add(new Route('admin.settings', SettingsHandler::PATH, SettingsHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->routes()->add(new Route('admin.storage', StorageHandler::PATH, StorageHandler::class, middleware: [AdminAccessMiddleware::class]));
        $registrar->routes()->add(new Route('admin.server_notice.hide', HideServerNoticeHandler::PATH, HideServerNoticeHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $registrar->routes()->add(new Route('admin.languages', LanguagesHandler::PATH, LanguagesHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->routes()->add(new Route('admin.languages.remove', RemoveLanguageHandler::PATH, RemoveLanguageHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('dashboard', 'Dashboard', 'admin.dashboard', position: -100));
        $registrar->adminMenu()->add(new AdminMenuItem('admin.settings', 'Site settings', 'admin.settings', SettingsHandler::PERMISSION, position: 60));
    }
}
