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
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;
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
        $registrar->services()->autowire(DisplayChanged::class);
        $registrar->subscribers()->listen(SettingsChanged::class, DisplayChanged::class);

        $settings = $registrar->settings();
        $settings->add(new Setting(SiteProfile::DESCRIPTION, 'About the site', SettingType::LongText, help: 'One or two sentences about the site and who runs it. Search engines and AI search engines show it with the site.'));
        $settings->add(new Setting(SiteProfile::LEGAL_NAME, 'Full legal name', SettingType::Text, help: 'For an organisation, its registered name, if it differs from the site name. Leave it empty otherwise.'));
        $settings->add(new Setting(SiteProfile::EMAIL, 'Contact email address', SettingType::Text, help: 'Given to search engines with the site’s details, so people can reach you. Leave it empty to keep it private.'));
        $settings->add(new Setting(SiteProfile::PHONE, 'Contact phone number', SettingType::Text, help: 'With the country code, for example +44 20 7946 0000. Leave it empty to keep it private.'));
        $settings->add(new Setting(SiteProfile::ADDRESS, 'Postal address', SettingType::LongText, help: 'Only if visitors should know it. Leave it empty to keep it private.'));
        $settings->add(new Setting(SiteProfile::SAME_AS, 'Profiles on other sites', SettingType::LongText, help: 'The full addresses of the site’s profiles elsewhere (social networks, directories), one per line, each starting with https://. Search engines use them to know these profiles belong to the same owner.'));
    }
}
