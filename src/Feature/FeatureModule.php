<?php

declare(strict_types=1);

namespace SightPress\Core\Feature;

use SightPress\Core\Feature\Command\DisableSiteModuleCommand;
use SightPress\Core\Feature\Command\EnableSiteModuleCommand;
use SightPress\Core\Feature\Command\ListSiteModulesCommand;
use SightPress\Core\Feature\Http\DeleteFeatureDataHandler;
use SightPress\Core\Feature\Http\FeaturesHandler;
use SightPress\Core\Feature\Http\TurnOffFeatureHandler;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Access\Permission;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * Per-site module switches (ADR-0034): the `site_module` table with each site's choices, the *Features* admin screen
 * (`/admin/features`, permission `module.manage`, admins only by default) and the `site:module:*` commands. Always
 * on.
 */
final class FeatureModule implements ModuleInterface
{
    public const string ID = 'features';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [
                new ModuleRequirement('site', '^0.1'),
                new ModuleRequirement('rbac', '^0.1'),
                new ModuleRequirement('admin', '^0.1'),
            ],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new FeatureMigrations());
        $registrar->views()->add('feature', dirname(__DIR__, 2) . '/resources/views/feature');
        $services = $registrar->services();
        foreach ([FeaturesHandler::class, TurnOffFeatureHandler::class, DeleteFeatureDataHandler::class] as $handler) {
            $services->autowire($handler);
        }
        $registrar->permissions()->add(new Permission(
            FeaturesHandler::PERMISSION,
            'Manage features',
            'Turn the site’s optional features on and off, and delete their data.',
            ['admin'],
        ));
        $routes = $registrar->routes();
        $routes->add(new Route('features.list', FeaturesHandler::PATH, FeaturesHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('features.turn_off', TurnOffFeatureHandler::PATH, TurnOffFeatureHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('features.delete_data', DeleteFeatureDataHandler::PATH, DeleteFeatureDataHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('features.list', 'Features', 'features.list', FeaturesHandler::PERMISSION, position: 65));
        foreach ([ListSiteModulesCommand::class, EnableSiteModuleCommand::class, DisableSiteModuleCommand::class] as $command) {
            $registrar->commands()->add($command);
        }
    }
}
