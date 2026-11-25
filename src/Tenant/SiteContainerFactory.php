<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use League\Flysystem\FilesystemOperator;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SightPress\Core\Admin\ModuleScreens;
use SightPress\Core\Audit\ModuleAuditTrail;
use SightPress\Core\Infrastructure\Settings\SiteSettingValues;
use SightPress\Module\Audit\AuditTrail;
use SightPress\Module\Http\Screens;
use SightPress\Module\Setting\SettingValues;
use SightPress\Module\Site\SiteInfo;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use ReflectionClass;
use SightPress\Core\Infrastructure\Container\Services;
use Composer\InstalledVersions;
use SightPress\Core\Template\AssetUrls;
use SightPress\Core\Template\TemplateExtension;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\TwigFactory;
use SightPress\Core\Theme\Contract\ThemeLayouts;
use SightPress\Core\Theme\Contract\ThemeStatus;
use SightPress\Core\Theme\SiteTheme;
use Twig\Environment;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Media\MediaUrlSigner;
use SightPress\Core\Theme\PublishedAssets;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Infrastructure\Event\EventDispatcher;
use SightPress\Core\Infrastructure\Event\ListenerProvider;
use SightPress\Core\Module\ModulePolicy;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Infrastructure\Dashboard\DashboardWidgets;
use SightPress\Core\Infrastructure\Assistant\AssistantAccess;
use SightPress\Core\Infrastructure\Assistant\AssistantExtensions;
use SightPress\Core\Infrastructure\Sms\PhoneNumbers;
use SightPress\Core\Infrastructure\Sms\SmsProviders;
use SightPress\Core\Infrastructure\Search\SearchExtensions;
use SightPress\Core\Infrastructure\Seo\SeoExtensions;
use SightPress\Core\Content\BlockTypeCatalogue;
use Psr\Log\LoggerInterface;
use SightPress\Core\Module\SiteModuleStore;
use SightPress\Core\Navigation\AdminNavigation;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Http\Upload\DirectUploadCheck;
use SightPress\Core\Http\Upload\ServerNotice;
use SightPress\Core\Http\Upload\UploadLimits;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\UploadAllowance;
use SightPress\Core\Media\UploadRefusals;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Builds the site container (ADR-0003): the services that belong to one site, on top of the tenant container.
 *
 * Site services are lazy: the connection and the entity manager are lazy objects, so the DSN is only decrypted, and
 * they are only built, when they are first used (for example by a query) — services that merely depend on them
 * cost nothing on requests that don't touch the database. The connection only opens on its first query. The site's
 * file storage ({@see FilesystemOperator}) creates its folder only when the first file is written. Each site has its
 * own event dispatcher, so listeners of events dispatched by site services are built with the site's services. Containers are kept per site for
 * the process and rebuilt when the registry hands out a new {@see Site} (it does after any change); {@see evict()}
 * drops one, {@see reset()} all (for long-running workers).
 */
final class SiteContainerFactory
{
    /**
     * Ids of the services every site container defines itself; no module may register one of them.
     */
    public const array SITE_SERVICES = [
        Site::class,
        Connection::class,
        EntityManagerInterface::class,
        EntityManager::class,
        FilesystemOperator::class,
        ListenerProviderInterface::class,
        EventDispatcherInterface::class,
        SiteModuleStore::class,
    ];


    /** @var array<string, array{Site, ContainerInterface}> */
    private array $containers = [];

    /**
     * @param list<string>         $entityPaths    folders with entity classes (attribute mapping)
     * @param array<string, mixed> $moduleServices PHP-DI definitions of the services modules registered; each site
     *                                             gets its own instances, built against its own database
     * @param ModuleRegistry|null  $modules        what every module the tenant loaded registered; with $policy, each
     *                                             site gets only what its modules that are on registered (ADR-0034)
     */
    public function __construct(
        private readonly ContainerInterface $tenant,
        private readonly SecretBox $secretBox,
        private readonly TenantConfig $config,
        private readonly array $entityPaths = [],
        private readonly array $moduleServices = [],
        private readonly ?ModuleRegistry $modules = null,
        private readonly ?ModulePolicy $policy = null,
    ) {
    }

    public function forSite(Site $site): ContainerInterface
    {
        $cached = $this->containers[$site->slug] ?? null;
        if ($cached !== null && $cached[0] === $site) {
            return $cached[1];
        }

        $container = new SiteContainer($this->tenant, $this->definitions($site));
        $this->containers[$site->slug] = [$site, $container];

        return $container;
    }

    /**
     * Forgets a site's container, for example after its status or database changed.
     */
    public function evict(string $slug): void
    {
        unset($this->containers[$slug]);
    }

    public function reset(): void
    {
        $this->containers = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(Site $site): array
    {
        $secretBox = $this->secretBox;
        $config = $this->config;
        $entityPaths = $this->entityPaths;
        $tenantModules = $this->modules;

        return [
            ...$this->moduleServices,
            ...$this->moduleDefinitions(),
            // The name, default language and time zone the site's admins chose win over the registry. A site whose
            // database isn't ready yet (while it is being created) keeps the registry's values.
            Site::class => factory(static function (ContainerInterface $container) use ($site): Site {
                if (!$container->has(SiteSettings::class)) {
                    return $site;
                }
                try {
                    return SiteProfile::display($site, Services::get($container, SiteSettings::class));
                } catch (DbalException) {
                    return $site;
                }
            }),
            // Who the site is, for modules (module-interface SiteInfo): read through Site, so admins' choices win.
            SiteInfo::class => factory(static function (ContainerInterface $container): SiteInfo {
                $current = Services::get($container, Site::class);

                return new SiteInfo($current->id, $current->slug, $current->name, $current->defaultLocale, $current->locales);
            }),
            // module-interface services (ADR-0059): core's, not a module's, so any module may use them.
            Screens::class => autowire(ModuleScreens::class),
            SettingValues::class => autowire(SiteSettingValues::class),
            AuditTrail::class => autowire(ModuleAuditTrail::class),
            MediaLimits::class => factory(static fn (): MediaLimits => $config->mediaLimits->forSite($site)),
            // Shared upload services, not a module's: every module's upload form uses them, whichever modules are on.
            UploadLimits::class => factory(static fn (ContainerInterface $container): UploadLimits => new UploadAllowance(
                Services::get($container, MediaLimits::class),
                Services::get($container, ServerLimits::class),
            )),
            MediaUrlSigner::class => factory(static fn (ContainerInterface $container): MediaUrlSigner => new MediaUrlSigner($config->secret, $site->id, Services::get($container, ClockInterface::class))),
            DirectUploadCheck::class => factory(static fn (ContainerInterface $container): DirectUploadCheck => new DirectUploadCheck(
                Services::get($container, Site::class),
                Services::get($container, HttpClientInterface::class),
                new FilesystemAdapter('direct-upload-' . $site->id, 0, $config->cacheDir),
            )),
            UploadRefusals::class => autowire(),
            ServerNotice::class => autowire(),
            Connection::class => factory(static fn (): Connection => new ReflectionClass(Connection::class)->newLazyProxy(
                static fn (): Connection => DriverManager::getConnection(
                    DatabaseDsn::parse($secretBox->decrypt($site->databaseDsn))->connectionParams(),
                ),
            )),
            EntityManagerInterface::class => factory(
                static fn (ContainerInterface $container): EntityManagerInterface => new ReflectionClass(EntityManager::class)->newLazyProxy(
                    static function () use ($container, $site, $config, $entityPaths): EntityManager {
                        $cache = $config->debug
                            ? new ArrayAdapter()
                            : new FilesystemAdapter('doctrine-' . $site->id, 0, $config->cacheDir);
                        $orm = ORMSetup::createAttributeMetadataConfig($entityPaths, $config->debug, null, $cache);
                        $orm->enableNativeLazyObjects(true);

                        return new EntityManager(Services::get($container, Connection::class), $orm);
                    },
                ),
            ),
            EntityManager::class => get(EntityManagerInterface::class),
            FilesystemOperator::class => factory(static fn (): FilesystemOperator => new SiteFilesystems($secretBox, $config->sitesDir)->forSite($site)),
            SiteFilesystems::class => factory(static fn (): SiteFilesystems => new SiteFilesystems($secretBox, $config->sitesDir)),
            ListenerProviderInterface::class => factory(static fn (ContainerInterface $container): ListenerProviderInterface => new ListenerProvider(
                $container,
                Services::get($container, ModuleRegistry::class),
            )),
            EventDispatcherInterface::class => factory(static fn (ContainerInterface $container): EventDispatcherInterface => new EventDispatcher(
                Services::get($container, ListenerProviderInterface::class),
            )),
            SearchExtensions::class => factory(static fn (ContainerInterface $container): SearchExtensions => new SearchExtensions(
                $container,
                Services::get($container, ModuleRegistry::class),
            )),
            SeoExtensions::class => factory(static fn (ContainerInterface $container): SeoExtensions => new SeoExtensions(
                $container,
                Services::get($container, ModuleRegistry::class),
                Services::get($container, LoggerInterface::class),
            )),
            AssistantExtensions::class => factory(static fn (ContainerInterface $container): AssistantExtensions => new AssistantExtensions(
                $container,
                Services::get($container, ModuleRegistry::class),
            )),
            SmsProviders::class => factory(static fn (ContainerInterface $container): SmsProviders => new SmsProviders(
                $container,
                $tenantModules ?? Services::get($container, ModuleRegistry::class),
                Services::get($container, ModuleRegistry::class),
            )),
            PhoneNumbers::class => autowire(),
            AssistantAccess::class => factory(static fn (ContainerInterface $container): AssistantAccess => new AssistantAccess(
                $container,
                Services::get($container, ModuleRegistry::class),
            )),
            DashboardWidgets::class => factory(static fn (ContainerInterface $container): DashboardWidgets => new DashboardWidgets(
                $container,
                Services::get($container, ModuleRegistry::class),
            )),
            EnabledModules::class => factory(static fn (ContainerInterface $container): EnabledModules => new EnabledModules(
                Services::get($container, ModuleRegistry::class),
            )),
            // The site's own theme (T-1): its templates come first, so the site's Twig environment and renderer are
            // the site's too.
            SiteTheme::class => autowire(),
            ThemeStatus::class => get(SiteTheme::class),
            ThemeLayouts::class => get(SiteTheme::class),
            TemplatePaths::class => factory(static fn (ContainerInterface $container): TemplatePaths => Services::get($container, SiteTheme::class)->templatePaths()),
            Environment::class => factory(static fn (ContainerInterface $container): Environment => new TwigFactory()->create(
                Services::get($container, TemplatePaths::class),
                Services::get($container, ModuleRegistry::class),
                Services::get($container, TemplateExtension::class),
                $config->cacheDir,
                $config->debug,
            )),
            TemplateRenderer::class => autowire(),
            // asset() addresses carry the site theme's version, so a theme change reaches browsers at once.
            AssetUrls::class => factory(static fn (ContainerInterface $container): AssetUrls => new AssetUrls(
                '/assets/',
                Services::get($container, SiteTheme::class)->assetVersion(InstalledVersions::getReference('sightpress/core')),
                $site->storage->cdnUrl === null ? [] : new PublishedAssets($config)->urls(Services::get($container, SiteTheme::class)->theme()->manifest(), $site->storage->cdnUrl),
                $site->storage->cdnUrl ?? '',
            )),
            TemplateExtension::class => autowire()
                ->constructorParameter('defaultViewer', new Viewer($config->adminLocale, $config->defaultTimezone))
                ->constructorParameter('modules', $this->modules),
        ];
    }

    /**
     * The site's own view of the modules (ADR-0034): what its modules that are on registered, read from its
     * `site_module` choices when the container first needs it, and the admin navigation and settings built from
     * that, so listeners, routes, menu entries and settings of modules that are off are left out.
     *
     * @return array<string, mixed>
     */
    private function moduleDefinitions(): array
    {
        $modules = $this->modules;
        $policy = $this->policy;
        if ($modules === null || $policy === null) {
            return [];
        }

        return [
            SiteModuleStore::class => factory(static fn (ContainerInterface $container): SiteModuleStore => new SiteModuleStore(
                Services::get($container, Connection::class),
            )),
            ModuleRegistry::class => factory(static fn (ContainerInterface $container): ModuleRegistry => $modules->only(
                $policy->onIds($policy->selection(Services::get($container, SiteModuleStore::class)->choices())),
            )),
            AdminNavigation::class => factory(static fn (ContainerInterface $container): AdminNavigation => AdminNavigation::fromRegistry(
                Services::get($container, ModuleRegistry::class),
            )),
            SettingCatalogue::class => factory(static fn (ContainerInterface $container): SettingCatalogue => SettingCatalogue::fromRegistry(
                Services::get($container, ModuleRegistry::class),
            )),
            // A module that is off adds no block type to the site's editor, and its blocks aren't shown (ADR-0057).
            BlockTypeCatalogue::class => factory(static fn (ContainerInterface $container): BlockTypeCatalogue => BlockTypeCatalogue::fromRegistry(
                Services::get($container, ModuleRegistry::class),
                $container,
            )),
        ];
    }
}
