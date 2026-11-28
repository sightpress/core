<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use DI\Container;
use League\Flysystem\FilesystemOperator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainer;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use PDO;
use stdClass;

#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantAssistant::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantThemes::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Infrastructure\Assistant\AssistantExtensions::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Infrastructure\Assistant\AssistantAccess::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Infrastructure\Sms\SmsProviders::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Infrastructure\Sms\PhoneNumbers::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Rbac\Contract\Actor::class)]
#[CoversClass(SiteContainerFactory::class)]
#[UsesNamespace('SightPress\Core\Http\Session')]
#[CoversClass(SiteContext::class)]
#[CoversClass(SiteContainer::class)]
#[UsesClass(Services::class)]
#[UsesClass(DatabaseDsn::class)]
#[UsesClass(SecretBox::class)]
#[UsesClass(\SightPress\Core\Template\Viewer::class)]
#[UsesClass(\SightPress\Core\Site\Contract\SiteProfile::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Module')]
#[UsesNamespace('SightPress\Core\Infrastructure\Event')]
#[UsesNamespace('SightPress\Core\Infrastructure\Search')]
#[UsesClass(\SightPress\Core\Media\MediaLimits::class)]
#[UsesClass(\SightPress\Core\Infrastructure\Storage\SiteFilesystems::class)]
final class SiteContainerFactoryTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testEachSiteGetsItsOwnServices(): void
    {
        $factory = $this->factory();
        $demo = $factory->forSite(SiteFixture::site('demo', ['demo.example']));
        $blog = $factory->forSite(SiteFixture::site('blog', ['blog.example']));

        self::assertSame('demo', Services::get($demo, Site::class)->slug);
        self::assertSame('blog', Services::get($blog, Site::class)->slug);
        self::assertNotSame(Services::get($demo, Connection::class), Services::get($blog, Connection::class));
        self::assertSame(Services::get($demo, Connection::class), Services::get($demo, Connection::class));
    }

    public function testTheSiteContainerSeesTenantServices(): void
    {
        $tenant = new Container([stdClass::class => new stdClass()]);

        $site = $this->factory($tenant)->forSite(SiteFixture::site());

        self::assertSame($tenant->get(stdClass::class), $site->get(stdClass::class));
        self::assertTrue($site->has(stdClass::class));
        self::assertTrue($site->has(Connection::class));
        self::assertFalse($site->has('acme.missing'));
    }

    public function testTheEntityManagerUsesTheSiteConnection(): void
    {
        foreach ([true, false] as $debug) {
            $container = $this->factory(debug: $debug)->forSite(SiteFixture::site());

            $entityManager = Services::get($container, EntityManagerInterface::class);

            self::assertSame(Services::get($container, Connection::class), $entityManager->getConnection());
            self::assertSame($entityManager, Services::get($container, EntityManager::class));
            self::assertTrue($entityManager->getConfiguration()->isNativeLazyObjectsEnabled());
            self::assertInstanceOf(PDO::class, $entityManager->getConnection()->getNativeConnection());
        }
    }

    public function testNothingIsDecryptedUntilTheDatabaseIsNeeded(): void
    {
        $other = new SecretBox('another-secret-0123456789abcdef0123456789');
        $site = SiteFixture::site(secretBox: $other);
        $container = $this->factory()->forSite($site);

        self::assertSame($site, $container->get(Site::class));
        $connection = Services::get($container, Connection::class);
        Services::get($container, EntityManagerInterface::class);

        $this->expectException(SecretBoxException::class);

        $connection->getDatabasePlatform();
    }

    public function testKeepsAContainerPerSiteUntilEvicted(): void
    {
        $factory = $this->factory();
        $site = SiteFixture::site();
        $first = $factory->forSite($site);

        self::assertSame($first, $factory->forSite($site));
        self::assertNotSame($first, $factory->forSite(SiteFixture::site()));

        $second = $factory->forSite($site);
        $factory->evict('demo');
        $third = $factory->forSite($site);
        self::assertNotSame($second, $third);

        $factory->reset();
        self::assertNotSame($third, $factory->forSite($site));
    }

    public function testTheSiteHasTheNameLanguageAndTimeZoneItsAdminsChose(): void
    {
        $settings = new class () implements \SightPress\Core\Site\Contract\SiteSettings {
            /** @var array<string, string> */
            public array $values = [];

            public bool $broken = false;

            public function get(string $key): ?string
            {
                if ($this->broken) {
                    throw new \Doctrine\DBAL\Exception\TableNotFoundException(new \Doctrine\DBAL\Driver\PDO\Exception('no table'), null);
                }

                return $this->values[$key] ?? null;
            }

            public function set(string $key, string $value): void
            {
                $this->values[$key] = $value;
            }
        };
        $settings->values = ['site.name' => 'Riverside Reading Room', 'site.default_timezone' => 'Asia/Tokyo', 'site.default_locale' => 'fr-FR'];
        $factory = new SiteContainerFactory(
            new Container(),
            SiteFixture::secretBox(),
            TenantConfig::fromArray(['cache_dir' => $this->directory->path], '/srv/app', ['APP_DEBUG' => '1']),
            [],
            [\SightPress\Core\Site\Contract\SiteSettings::class => $settings],
        );
        $registry = SiteFixture::site('demo');

        $site = Services::get($factory->forSite($registry), Site::class);
        self::assertSame(['Riverside Reading Room', 'Asia/Tokyo', $registry->defaultLocale], [$site->name, $site->defaultTimezone, $site->defaultLocale], 'a language the site isn’t written in is ignored');
        $info = Services::get($factory->forSite($registry), \SightPress\Module\Site\SiteInfo::class);
        self::assertSame([$registry->id, 'demo', 'Riverside Reading Room', 'en-GB', ['en-GB']], [$info->id, $info->slug, $info->name, $info->defaultLocale, $info->locales], 'modules see the admins\' choices too');

        $settings->broken = true;
        $factory->reset();
        self::assertSame($registry, Services::get($factory->forSite($registry), Site::class), 'a database without settings yet keeps the registry');
        self::assertSame('demo', Services::get($this->factory()->forSite($registry), Site::class)->slug, 'no settings service, no change');
    }

    public function testModuleServicesAreBuiltPerSiteAgainstTheSiteDatabase(): void
    {
        $factory = new SiteContainerFactory(
            new Container(),
            SiteFixture::secretBox(),
            TenantConfig::fromArray(['cache_dir' => $this->directory->path], '/srv/app', ['APP_DEBUG' => '1']),
            [],
            ['acme.service' => \DI\factory(static fn (Connection $connection): \ArrayObject => new \ArrayObject([$connection]))],
        );
        $demo = $factory->forSite(SiteFixture::site('demo'));
        $blog = $factory->forSite(SiteFixture::site('blog'));

        $service = Services::get($demo, \ArrayObject::class, 'acme.service');
        self::assertSame(Services::get($demo, Connection::class), $service[0]);
        self::assertNotSame($service, $blog->get('acme.service'));
        self::assertContains(Connection::class, SiteContainerFactory::SITE_SERVICES);
    }

    public function testEachSiteDispatchesEventsToListenersBuiltWithItsOwnServices(): void
    {
        $module = new \SightPress\Core\Tests\Support\FakeModule('acme.listen', register: static function (\SightPress\Module\ModuleRegistrar $r): void {
            $r->subscribers()->listen(\ArrayObject::class, \SightPress\Core\Tests\Support\Modules\SiteNameListener::class);
        });
        $registry = \SightPress\Core\Module\ModuleRegistry::register(
            new \SightPress\Core\Module\ModuleLoader('0.2.0')->catalogue([new \SightPress\Core\Module\ModuleCandidate($module, 'acme/listen')])->resolve(new \SightPress\Core\Module\ModuleSelection()),
        );
        $tenant = new Container();
        $tenant->set(\SightPress\Core\Module\ModuleRegistry::class, $registry);
        $factory = new SiteContainerFactory($tenant, SiteFixture::secretBox(), TenantConfig::fromArray([], '/srv/app'), [], [
            \SightPress\Core\Tests\Support\Modules\SiteNameListener::class => \DI\autowire(),
        ]);

        $event = new \ArrayObject();
        Services::get($factory->forSite(SiteFixture::site('demo')), \Psr\EventDispatcher\EventDispatcherInterface::class)->dispatch($event);
        Services::get($factory->forSite(SiteFixture::site('blog')), \Psr\EventDispatcher\EventDispatcherInterface::class)->dispatch($event);

        self::assertSame(['demo', 'blog'], $event->getArrayCopy());
        self::assertContains(\Psr\EventDispatcher\EventDispatcherInterface::class, SiteContainerFactory::SITE_SERVICES);
    }

    public function testEachSiteSeesTheSearchProvidersAndModulesOfItsOwnModules(): void
    {
        $module = new \SightPress\Core\Tests\Support\FakeModule('acme.events', register: static function (\SightPress\Module\ModuleRegistrar $r): void {
            $r->search()->addProvider(\SightPress\Core\Tests\Support\Search\FakeSearchProvider::class);
        });
        $registry = \SightPress\Core\Module\ModuleRegistry::register(
            new \SightPress\Core\Module\ModuleLoader('0.2.0')->catalogue([new \SightPress\Core\Module\ModuleCandidate($module, 'acme/events')])->resolve(new \SightPress\Core\Module\ModuleSelection()),
        );
        $tenant = new Container();
        $tenant->set(\SightPress\Core\Module\ModuleRegistry::class, $registry);
        $factory = new SiteContainerFactory($tenant, SiteFixture::secretBox(), TenantConfig::fromArray([], '/srv/app'), [], [
            \SightPress\Core\Tests\Support\Search\FakeSearchProvider::class => \DI\autowire(),
        ]);
        $site = $factory->forSite(SiteFixture::site('demo'));

        self::assertSame(['acme.events'], array_keys(Services::get($site, \SightPress\Core\Infrastructure\Search\SearchExtensions::class)->providers()));
        self::assertSame([], Services::get($site, \SightPress\Core\Infrastructure\Assistant\AssistantExtensions::class)->providers());
        self::assertNull(Services::get($site, \SightPress\Core\Infrastructure\Assistant\AssistantAccess::class)->panel(new \SightPress\Core\Rbac\Contract\Actor(1), '/admin'), 'no assistant, no panel');
        self::assertTrue(Services::get($site, \SightPress\Core\Tenant\EnabledModules::class)->has('acme.events'));
        self::assertFalse(Services::get($site, \SightPress\Core\Tenant\EnabledModules::class)->has('search'));
        self::assertSame([], Services::get($site, \SightPress\Core\Infrastructure\Sms\SmsProviders::class)->all(), 'no text message providers');
        self::assertSame('GB', Services::get($site, \SightPress\Core\Infrastructure\Sms\PhoneNumbers::class)->regionOf('en-GB'));
    }

    public function testEachSiteKeepsItsFilesInItsOwnFolderCreatedOnFirstWrite(): void
    {
        $factory = new SiteContainerFactory(
            new Container(),
            SiteFixture::secretBox(),
            TenantConfig::fromArray(['cache_dir' => $this->directory->path, 'sites_dir' => $this->directory->path . '/sites'], '/srv/app'),
        );
        $demo = SiteFixture::site('demo');
        $files = Services::get($factory->forSite($demo), FilesystemOperator::class);

        self::assertDirectoryDoesNotExist($this->directory->path . '/sites');
        $files->write('media/hello.txt', 'hello');

        self::assertStringEqualsFile($this->directory->path . '/sites/' . $demo->id . '/media/hello.txt', 'hello');
        self::assertFalse(Services::get($factory->forSite(SiteFixture::site('blog')), FilesystemOperator::class)->fileExists('media/hello.txt'));
        self::assertContains(FilesystemOperator::class, SiteContainerFactory::SITE_SERVICES);
    }

    public function testTheContextTravelsOnTheRequest(): void
    {
        $context = new SiteContext(SiteFixture::site(), new Container());
        $request = new ServerRequest('GET', '/');

        self::assertNull(SiteContext::fromRequest($request));
        self::assertSame($context, SiteContext::fromRequest($request->withAttribute(SiteContext::class, $context)));
    }

    public function testEachSiteKeepsItsSessionsInTheStoreTheTenantChose(): void
    {
        $tenant = new Container([\Psr\Clock\ClockInterface::class => new \SightPress\Core\Tests\Support\FixedClock()]);
        $database = $this->factory($tenant)->forSite(SiteFixture::site());
        self::assertInstanceOf(\SightPress\Core\Http\Session\DatabaseSessionStore::class, $database->get(\SightPress\Core\Http\Session\SessionStore::class));

        $own = $this->factory($tenant, session: ['store' => \SightPress\Core\Tests\Support\SiteSessionStore::class])->forSite(SiteFixture::site('blog', ['blog.example']));
        $store = $own->get(\SightPress\Core\Http\Session\SessionStore::class);
        self::assertInstanceOf(\SightPress\Core\Tests\Support\SiteSessionStore::class, $store);
        self::assertSame('blog', $store->site->slug, 'a store of the operator\'s is built with the site\'s services');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function factory(?Container $tenant = null, bool $debug = true, array $session = []): SiteContainerFactory
    {
        return new SiteContainerFactory(
            $tenant ?? new Container(),
            SiteFixture::secretBox(),
            TenantConfig::fromArray(['cache_dir' => $this->directory->path, 'session' => $session], '/srv/app', ['APP_DEBUG' => $debug ? '1' : '0']),
        );
    }
}
