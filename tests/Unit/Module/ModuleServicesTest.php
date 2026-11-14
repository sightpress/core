<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Module;

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SightPress\Core\Module\LoadedModule;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleCatalogue;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleScopedContainer;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Module\ModuleServices;
use SightPress\Core\Module\Registered;
use SightPress\Core\Module\ServiceDefinition;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Module\ModuleRegistrar;
use stdClass;

#[CoversClass(ModuleServices::class)]
#[CoversClass(ModuleScopedContainer::class)]
#[UsesClass(ModuleRegistry::class)]
#[UsesClass(ModuleLoader::class)]
#[UsesClass(ModuleCatalogue::class)]
#[UsesClass(ModuleCandidate::class)]
#[UsesClass(ModuleSelection::class)]
#[UsesClass(LoadedModule::class)]
#[UsesClass(Registered::class)]
#[UsesClass(ServiceDefinition::class)]
#[UsesNamespace('SightPress\Core\Module\Registrar')]
final class ModuleServicesTest extends TestCase
{
    public function testFactoriesOnlyReachTheServicesTheirModuleMayUse(): void
    {
        $seen = [];
        $container = $this->container(
            new FakeModule('media', register: static function (ModuleRegistrar $r): void {
                $r->services()->factory('media.storage', static fn (): stdClass => new stdClass(), public: true);
                $r->services()->factory('media.secret', static fn (): stdClass => new stdClass());
            }),
            new FakeModule('gallery', ['media' => '*'], register: static function (ModuleRegistrar $r) use (&$seen): void {
                $r->services()->factory('gallery.album', static function (ContainerInterface $c) use (&$seen): stdClass {
                    $seen = [
                        'storage' => $c->has('media.storage'),
                        'secret' => $c->has('media.secret'),
                        'core' => $c->has('core.clock'),
                        'missing' => $c->has('nothing'),
                    ];
                    $album = new stdClass();
                    $album->storage = $c->get('media.storage');
                    $album->clock = $c->get('core.clock');

                    return $album;
                });
                $r->services()->factory('gallery.thief', static fn (ContainerInterface $c): mixed => $c->get('media.secret'));
                $r->services()->autowire(stdClass::class, 'gallery.plain');
            }),
        );

        $album = $container->get('gallery.album');

        self::assertInstanceOf(stdClass::class, $album);
        self::assertSame($container->get('media.storage'), $album->storage);
        self::assertSame(['storage' => true, 'secret' => false, 'core' => true, 'missing' => false], $seen);
        self::assertInstanceOf(stdClass::class, $container->get('gallery.plain'));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "gallery" uses service "media.secret", which is private to module "media".');

        $container->get('gallery.thief');
    }

    public function testAFactoryMustReturnAnObject(): void
    {
        $container = $this->container(new FakeModule('acme', register: static function (ModuleRegistrar $r): void {
            $r->services()->factory('acme.answer', static fn (): int => 42);
        }));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('The factory of service "acme.answer" (module "acme") returned int instead of an object.');

        $container->get('acme.answer');
    }

    public function testAModuleCantReplaceACoreService(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "acme" registers service "core.clock", which is a core service. Use another id.');

        $this->container(new FakeModule('acme', register: static function (ModuleRegistrar $r): void {
            $r->services()->factory('core.clock', static fn (): stdClass => new stdClass());
        }));
    }

    private function container(FakeModule ...$modules): ContainerInterface
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(array_map(
            static fn (FakeModule $module): ModuleCandidate => new ModuleCandidate($module, 'sightpress/core'),
            array_values($modules),
        ))->resolve(new ModuleSelection()));
        $core = ['core.clock' => new stdClass()];

        return new ContainerBuilder()
            ->addDefinitions($core)
            ->addDefinitions(ModuleServices::definitions($registry, array_keys($core)))
            ->build();
    }
}
