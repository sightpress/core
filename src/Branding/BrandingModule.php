<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

use SightPress\Core\Branding\Command\RegenerateBrandingCommand;
use SightPress\Core\Branding\Command\ResetBrandingCommand;
use SightPress\Core\Branding\Command\SetBrandingCommand;
use SightPress\Core\Branding\Command\SiteBrandings;
use SightPress\Core\Branding\Http\BrandingAdminHandler;
use SightPress\Core\Branding\Http\BrandingFileHandler;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Core\Branding\Http\ManifestHandler;
use SightPress\Core\Branding\Provisioning\DefaultBranding;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * Branding: each site's logo, icon and share image ({@see BrandingService}; generated from the site's name until
 * someone uploads their own), served at the addresses browsers ask for, the web app manifest, and the `<head>` tags
 * of every public page ({@see BrandingHead}: icons, Open Graph, Twitter card, schema.org owner and website). CLI
 * `site:branding:set|reset|regenerate`; new sites get {@see DefaultBranding}. Always on.
 */
final class BrandingModule implements ModuleInterface
{
    public const string ID = 'branding';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('rbac', '^0.1'), new ModuleRequirement('media', '^0.1'), new ModuleRequirement('page', '^0.1'), new ModuleRequirement('admin', '^0.1')],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/branding');
        $services = $registrar->services();
        foreach ([BrandImages::class, BrandingHead::class, BrandingFileHandler::class, ManifestHandler::class, SiteBrandings::class, DefaultBranding::class, BrandingAdminHandler::class, RenameListener::class] as $class) {
            $services->autowire($class);
        }
        $services->autowire(BrandingService::class, public: true);

        foreach ([SetBrandingCommand::class, ResetBrandingCommand::class, RegenerateBrandingCommand::class] as $command) {
            $registrar->commands()->add($command);
        }
        $registrar->subscribers()->listen(PublicPageRendering::class, BrandingHead::class);
        $registrar->subscribers()->listen(SiteProvisioning::class, DefaultBranding::class, DefaultBranding::PRIORITY);
        $registrar->subscribers()->listen(SettingsChanged::class, RenameListener::class);

        $routes = $registrar->routes();
        foreach (BrandingFileHandler::ROOT_FILES as $file) {
            $routes->add(new Route('branding.' . str_replace(['.', '-'], ['_', '_'], $file), '/' . $file, BrandingFileHandler::class, ['GET', 'HEAD']));
        }
        $routes->add(new Route('branding.file', '/branding/{file}', BrandingFileHandler::class, ['GET', 'HEAD']));
        $routes->add(new Route('branding.manifest', ManifestHandler::PATH, ManifestHandler::class, ['GET', 'HEAD']));
        $routes->add(new Route('branding.admin', BrandingAdminHandler::PATH, BrandingAdminHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('branding.admin', 'Branding', 'branding.admin', BrandingAdminHandler::PERMISSION, 'admin.settings', 10));
    }
}
