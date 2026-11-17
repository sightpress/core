<?php

declare(strict_types=1);

namespace SightPress\Core\Audit;

use SightPress\Core\Audit\Http\AuditLogHandler;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * The audit log: an append-only record of who changed pages, media, menus, people, roles and site settings, built
 * from the events the modules dispatch, with an admin screen (filters, CSV export) for people with `audit.view`.
 * Always on.
 */
final class AuditModule implements ModuleInterface
{
    public const string ID = 'audit';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [
                new ModuleRequirement('site', '^0.1'),
                new ModuleRequirement('user', '^0.1'),
                new ModuleRequirement('rbac', '^0.1'),
                new ModuleRequirement('media', '^0.1'),
                new ModuleRequirement('page', '^0.1'),
                new ModuleRequirement('menu', '^0.1'),
                new ModuleRequirement('admin', '^0.1'),
            ],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new AuditMigrations());
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/audit');
        $services = $registrar->services();
        foreach ([AuditLog::class, AuditRecorder::class, AuditLogHandler::class] as $class) {
            $services->autowire($class);
        }
        foreach (AuditRecorder::EVENTS as $event) {
            $registrar->subscribers()->listen($event, AuditRecorder::class, -100);
        }
        $routes = $registrar->routes();
        $routes->add(new Route('audit.log', AuditLogHandler::PATH, AuditLogHandler::class, middleware: [AdminAccessMiddleware::class]));
        $routes->add(new Route('audit.csv', AuditLogHandler::CSV_PATH, AuditLogHandler::class, middleware: [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('audit.log', 'Audit log', 'audit.log', AuditLogHandler::PERMISSION, position: 70));
    }
}
