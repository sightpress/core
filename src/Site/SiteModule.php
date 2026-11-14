<?php

declare(strict_types=1);

namespace SightPress\Core\Site;

use SightPress\Core\Site\Command\CheckRegistryCommand;
use SightPress\Core\Site\Command\UpgradeRegistryCommand;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * Sites of the tenant: the `site:*` console commands that look after the site registry (ADR-0002), and each site's
 * stored settings ({@see SiteSettings}, public). Always on.
 */
final class SiteModule implements ModuleInterface
{
    public const string ID = 'site';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '0.1.0', required: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->commands()->add(CheckRegistryCommand::class);
        $registrar->commands()->add(UpgradeRegistryCommand::class);
        $registrar->migrations()->add(new SiteMigrations());
        $registrar->services()->autowire(DbalSiteSettings::class, SiteSettings::class, public: true);
    }
}
