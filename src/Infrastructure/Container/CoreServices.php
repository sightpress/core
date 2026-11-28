<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Container;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Log\LoggerInterface;
use SightPress\Core\Http\Upload\UploadProbeClient;
use SightPress\Core\Infrastructure\Http\OutboundHosts;
use SightPress\Core\Infrastructure\Http\OutboundHttp;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\Session\FileSessionStore;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Infrastructure\Clock\SystemClock;
use SightPress\Core\Infrastructure\Event\EventDispatcher;
use SightPress\Core\Infrastructure\Event\ListenerProvider;
use SightPress\Core\Http\Error\ErrorResponder;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Infrastructure\Mail\TransportMailSender;
use Symfony\Component\Mailer\Transport;
use SightPress\Core\Access\PermissionCatalogue;
use SightPress\Core\Content\BlockIconCatalogue;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCatalogue;
use SightPress\Core\Module\ModulePolicy;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleServices;
use SightPress\Core\Navigation\AdminNavigation;
use SightPress\Core\Http\Security\SecurityHeadersMiddleware;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Tenant\FileSiteRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRegistryFile;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Template\AssetUrls;
use SightPress\Core\Template\TemplateExtension;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\TwigFactory;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Http\Upload\UploadProbe;
use SightPress\Core\Media\MediaLimits;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Environment;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Service definitions of the tenant container (ADR-0003): the services that are the same for every site.
 */
final class CoreServices
{
    /**
     * @return array<string, mixed>
     */
    public static function definitions(TenantConfig $config, ModuleRegistry $modules, ModulePolicy $policy): array
    {
        return [
            TenantConfig::class => $config,
            ModuleRegistry::class => $modules,
            ModulePolicy::class => $policy,
            ModuleCatalogue::class => $policy->catalogue(),
            ClockInterface::class => autowire(SystemClock::class),
            // Only for requests that belong to no site: each site's own store is in its container (`session.store`).
            SessionStore::class => autowire(FileSessionStore::class)
                ->constructorParameter('directory', $config->sessionPath)
                ->constructorParameter('idleTimeout', $config->sessionIdleTimeout),
            ThemeRegistry::class => factory(static fn (): ThemeRegistry => ThemeRegistry::forProject($config->projectDir, $config->debug ? null : $config->cacheDir)),
            TemplatePaths::class => factory(static fn (ContainerInterface $container): TemplatePaths => new TemplatePaths(
                [Services::get($container, ThemeRegistry::class)->default()->paths()->root],
                TemplatePaths::core(),
            )),
            AssetUrls::class => autowire()->constructorParameter('version', \Composer\InstalledVersions::getReference('sightpress/core')),
            Translations::class => factory(static fn (): Translations => Translations::fromDirectories(
                // Core's catalogue first, then each module's own domain (module-interface translations(), ADR-0059).
                [dirname(TemplatePaths::core()) . '/translations', ...$modules->translations()],
                $config->adminLocale,
            )),
            TemplateExtension::class => autowire()
                ->constructorParameter('defaultViewer', new Viewer($config->adminLocale, $config->defaultTimezone))
                ->constructorParameter('modules', $modules),
            Environment::class => factory(static fn (ContainerInterface $container): Environment => new TwigFactory()->create(
                Services::get($container, TemplatePaths::class),
                $modules,
                Services::get($container, TemplateExtension::class),
                $config->cacheDir,
                $config->debug,
            )),
            MailSender::class => factory(static fn (): MailSender => new TransportMailSender(
                Transport::fromDsn($config->mailerDsn),
                $config->mailFrom,
                $config->mailFromName,
            )),
            ValidatorInterface::class => factory(static fn (): ValidatorInterface => Validation::createValidator()),
            RateLimiters::class => factory(static fn (): RateLimiters => new RateLimiters(
                new CacheStorage(new FilesystemAdapter('rate_limits', 0, $config->cacheDir)),
                RateLimiters::POLICIES + RateLimiters::smsPolicies($config->sms->limits),
                $config->secret,
            )),
            ListenerProviderInterface::class => autowire(ListenerProvider::class),
            EventDispatcherInterface::class => autowire(EventDispatcher::class),
            Psr17Factory::class => autowire(),
            ResponseFactoryInterface::class => get(Psr17Factory::class),
            StreamFactoryInterface::class => get(Psr17Factory::class),
            ServerRequestFactoryInterface::class => get(Psr17Factory::class),
            UriFactoryInterface::class => get(Psr17Factory::class),
            UploadedFileFactoryInterface::class => get(Psr17Factory::class),
            LoggerFactory::class => autowire(),
            LoggerInterface::class => factory(
                static fn (ContainerInterface $container): LoggerInterface
                    => Services::get($container, LoggerFactory::class)->create(),
            ),
            SecretBox::class => autowire()->constructor($config->secret),
            SecurityHeadersMiddleware::class => autowire()->constructorParameter('sites', get(SiteRepository::class)),
            SiteFilesystems::class => autowire()->constructorParameter('sitesDir', $config->sitesDir),
            SiteRegistryFile::class => autowire()->constructor($config->registryPath),
            SiteRepository::class => autowire(FileSiteRepository::class),
            // Built by hand: PHP-DI would resolve the module service definitions if they were passed as a parameter.
            SiteContainerFactory::class => factory(static fn (ContainerInterface $container): SiteContainerFactory => new SiteContainerFactory(
                $container,
                Services::get($container, SecretBox::class),
                $config,
                CoreModules::entityPaths(),
                ModuleServices::definitions($modules, []),
                $modules,
                $policy,
            )),
            PermissionCatalogue::class => factory(static fn (): PermissionCatalogue => PermissionCatalogue::fromRegistry($modules)),
            AdminNavigation::class => factory(static fn (): AdminNavigation => AdminNavigation::fromRegistry($modules)),
            SettingCatalogue::class => factory(static fn (): SettingCatalogue => SettingCatalogue::fromRegistry($modules)),
            BlockTypeCatalogue::class => factory(
                static fn (ContainerInterface $container): BlockTypeCatalogue => BlockTypeCatalogue::fromRegistry($modules, $container),
            ),
            BlockIconCatalogue::class => factory(static fn (): BlockIconCatalogue => BlockIconCatalogue::fromRegistry($modules)),
            // Outgoing requests never wait long, and go only to the hosts a service the tenant or the site uses
            // declares (GD-1); a site gets its own allow-list (SiteContainerFactory). The client without it is kept
            // for the server's own checks of itself.
            OutboundHttp::INNER => factory(static fn (): HttpClientInterface => HttpClient::create(['timeout' => 5, 'max_redirects' => 0])),
            HttpClientInterface::class => factory(static fn (ContainerInterface $container): HttpClientInterface => new OutboundHttp(
                OutboundHttp::inner($container),
                OutboundHosts::of(OutboundHttp::TENANT_HOSTS),
            )),
            UploadProbeClient::class => autowire()->constructorParameter('http', get(OutboundHttp::INNER)),
            ServerLimits::class => factory(static fn (): ServerLimits => ServerLimits::current($config)),
            MediaLimits::class => factory(static fn (): MediaLimits => $config->mediaLimits->defaults),
            UploadProbe::class => autowire()->constructorParameter('secret', $config->secret),
            ErrorResponder::class => autowire()
                ->constructorParameter('debug', $config->debug)
                ->constructorParameter('noindex', !$config->environment->isProduction())
                ->constructorParameter('translations', get(Translations::class))
                ->constructorParameter('defaultLocale', $config->adminLocale),
        ];
    }
}
