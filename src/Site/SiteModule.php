<?php

declare(strict_types=1);

namespace SightPress\Core\Site;

use SightPress\Core\Site\Command\ArchiveSiteCommand;
use SightPress\Core\Site\Command\ChangeSiteDatabaseCommand;
use SightPress\Core\Site\Command\CheckRegistryCommand;
use SightPress\Core\Site\Command\CreateSiteCommand;
use SightPress\Core\Site\Command\DeleteSiteCommand;
use SightPress\Core\Site\Command\DisableSiteCommand;
use SightPress\Core\Site\Command\EnableSiteCommand;
use SightPress\Core\Site\Command\ListSitesCommand;
use SightPress\Core\Site\Command\MigrateSitesCommand;
use SightPress\Core\Site\Command\PurgeSiteCommand;
use SightPress\Core\Site\Command\UpgradeRegistryCommand;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * Sites of the tenant: the `site:*` console commands that create sites, change their status and look after the
 * site registry (ADR-0002); the {@see Contract\SiteProvisioning} event other modules use to set up a new site; and
 * each site's stored settings ({@see SiteSettings}, public). Always on.
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
        foreach ([
            CheckRegistryCommand::class,
            UpgradeRegistryCommand::class,
            CreateSiteCommand::class,
            ListSitesCommand::class,
            EnableSiteCommand::class,
            DisableSiteCommand::class,
            ArchiveSiteCommand::class,
            DeleteSiteCommand::class,
            PurgeSiteCommand::class,
            MigrateSitesCommand::class,
            ChangeSiteDatabaseCommand::class,
        ] as $command) {
            $registrar->commands()->add($command);
        }
        $registrar->migrations()->add(new SiteMigrations());
        $registrar->services()->autowire(DbalSiteSettings::class, SiteSettings::class, public: true);
    }
}
