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
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\Session\FileSessionStore;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Infrastructure\Clock\SystemClock;
use SightPress\Core\Infrastructure\Event\EventDispatcher;
use SightPress\Core\Infrastructure\Event\ListenerProvider;
use SightPress\Core\Http\Error\ErrorResponder;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Tenant\SiteRegistryProbe;
use SightPress\Core\Tenant\TenantConfig;
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
    public static function definitions(TenantConfig $config, ModuleRegistry $modules): array
    {
        return [
            TenantConfig::class => $config,
            ModuleRegistry::class => $modules,
            ClockInterface::class => autowire(SystemClock::class),
            SessionStore::class => autowire(FileSessionStore::class)
                ->constructorParameter('directory', $config->sessionPath)
                ->constructorParameter('idleTimeout', $config->sessionIdleTimeout),
            RateLimiters::class => factory(static fn (): RateLimiters => new RateLimiters(
                new CacheStorage(new FilesystemAdapter('rate_limits', 0, $config->cacheDir)),
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
            SiteRegistryProbe::class => autowire()->constructor($config->registryPath),
            ErrorResponder::class => autowire()
                ->constructorParameter('debug', $config->debug)
                ->constructorParameter('noindex', !$config->environment->isProduction()),
        ];
    }
}
