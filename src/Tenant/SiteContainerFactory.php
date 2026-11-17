<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use ReflectionClass;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Infrastructure\Event\EventDispatcher;
use SightPress\Core\Infrastructure\Event\ListenerProvider;
use SightPress\Core\Module\ModuleRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

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
    ];


    /** @var array<string, array{Site, ContainerInterface}> */
    private array $containers = [];

    /**
     * @param list<string>         $entityPaths    folders with entity classes (attribute mapping)
     * @param array<string, mixed> $moduleServices PHP-DI definitions of the services modules registered; each site
     *                                             gets its own instances, built against its own database
     */
    public function __construct(
        private readonly ContainerInterface $tenant,
        private readonly SecretBox $secretBox,
        private readonly TenantConfig $config,
        private readonly array $entityPaths = [],
        private readonly array $moduleServices = [],
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

        return [
            ...$this->moduleServices,
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
            FilesystemOperator::class => factory(static fn (): FilesystemOperator => new Filesystem(
                new LocalFilesystemAdapter($site->storage->root($config->sitesDir, $site->id), lazyRootCreation: true),
            )),
            ListenerProviderInterface::class => factory(static fn (ContainerInterface $container): ListenerProviderInterface => new ListenerProvider(
                $container,
                Services::get($container, ModuleRegistry::class),
            )),
            EventDispatcherInterface::class => factory(static fn (ContainerInterface $container): EventDispatcherInterface => new EventDispatcher(
                Services::get($container, ListenerProviderInterface::class),
            )),
        ];
    }
}
