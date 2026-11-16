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

#[CoversClass(SiteContainerFactory::class)]
#[CoversClass(SiteContext::class)]
#[CoversClass(SiteContainer::class)]
#[UsesClass(Services::class)]
#[UsesClass(DatabaseDsn::class)]
#[UsesClass(SecretBox::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
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

    private function factory(?Container $tenant = null, bool $debug = true): SiteContainerFactory
    {
        return new SiteContainerFactory(
            $tenant ?? new Container(),
            SiteFixture::secretBox(),
            TenantConfig::fromArray(['cache_dir' => $this->directory->path], '/srv/app', ['APP_DEBUG' => $debug ? '1' : '0']),
        );
    }
}
