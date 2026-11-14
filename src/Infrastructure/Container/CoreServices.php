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
use Psr\Log\LoggerInterface;
use SightPress\Core\Http\Error\ErrorResponder;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Tenant\SiteRegistryProbe;
use SightPress\Core\Tenant\TenantConfig;

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
    public static function definitions(TenantConfig $config): array
    {
        return [
            TenantConfig::class => $config,
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
