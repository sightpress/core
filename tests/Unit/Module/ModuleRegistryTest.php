<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Module;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\Listener;
use SightPress\Core\Module\LoadedModule;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleCatalogue;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Module\Registered;
use SightPress\Core\Module\ServiceDefinition;
use SightPress\Core\Module\ViewPath;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExampleBlockType;
use SightPress\Core\Tests\Support\Modules\ExampleCommand;
use SightPress\Core\Tests\Support\Modules\ExampleEvent;
use SightPress\Core\Tests\Support\Modules\ExampleMigrations;
use SightPress\Core\Tests\Support\Modules\RecordingListener;
use SightPress\Module\Access\Permission;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;
use stdClass;

#[CoversClass(ModuleRegistry::class)]
#[CoversClass(Registered::class)]
#[CoversClass(ServiceDefinition::class)]
#[CoversClass(Listener::class)]
#[CoversClass(ViewPath::class)]
#[CoversNamespace('SightPress\Core\Module\Registrar')]
#[UsesClass(ModuleLoader::class)]
#[UsesClass(ModuleCatalogue::class)]
#[UsesClass(ModuleCandidate::class)]
#[UsesClass(ModuleSelection::class)]
#[UsesClass(LoadedModule::class)]
final class ModuleRegistryTest extends TestCase
{
    public function testRecordsEverythingAModuleRegistersAndWhoRegisteredIt(): void
    {
        $factory = static fn (): stdClass => new stdClass();
        $registry = $this->registry(
            new FakeModule('user'),
            new FakeModule('acme.events', ['user' => '*'], register: static function (ModuleRegistrar $r) use ($factory): void {
                $r->services()->factory('acme.events.clock', $factory, public: true);
                $r->services()->autowire(stdClass::class);
                $r->routes()->add(new Route('events.list', '/events', CallbackHandler::class));
                $r->routes()->add(new Route('events.create', '/events', CallbackHandler::class, ['POST']));
                $r->blockTypes()->add(ExampleBlockType::class);
                $r->permissions()->add(new Permission('events.manage', 'Manage events'));
                $r->migrations()->add(new ExampleMigrations('Acme\Events\Migrations', __DIR__));
                $r->adminMenu()->add(new AdminMenuItem('events', 'Events', 'events.list'));
                $r->views()->add('acme_events', __DIR__);
                $r->subscribers()->listen(ExampleEvent::class, RecordingListener::class, 10);
                $r->commands()->add(ExampleCommand::class);
                $r->settings()->add(new Setting('events.per_page', 'Events per page', SettingType::Integer, 10));
            }),
        );

        self::assertSame(['user', 'acme.events'], $registry->modules());

        $services = $registry->services();
        self::assertSame(['acme.events.clock', stdClass::class], array_keys($services));
        self::assertSame('acme.events', $services['acme.events.clock']->module);
        self::assertSame($factory, $services['acme.events.clock']->item->factory);
        self::assertTrue($services['acme.events.clock']->item->public);
        self::assertSame(stdClass::class, $services[stdClass::class]->item->class);
        self::assertNull($services[stdClass::class]->item->factory);
        self::assertFalse($services[stdClass::class]->item->public);

        self::assertSame(['events.list', 'events.create'], array_map(static fn (Registered $r) => $r->item->name, $registry->routes()));
        self::assertSame(ExampleBlockType::class, $registry->blockTypes()[0]->item);
        self::assertSame('events.manage', $registry->permissions()[0]->item->name);
        self::assertSame('Acme\Events\Migrations', $registry->migrations()[0]->item->namespace());
        self::assertSame('events', $registry->adminMenu()[0]->item->id);
        self::assertSame(['acme_events', __DIR__], [$registry->views()[0]->item->namespace, $registry->views()[0]->item->directory]);
        $listener = $registry->listeners()[0]->item;
        self::assertSame([ExampleEvent::class, RecordingListener::class, 10], [$listener->event, $listener->listener, $listener->priority]);
        self::assertSame(ExampleCommand::class, $registry->commands()[0]->item);
        self::assertSame('events.per_page', $registry->settings()[0]->item->key);
        self::assertSame('acme.events', $registry->settings()[0]->module);
    }

    /**
     * @return iterable<string, array{Closure(ModuleRegistrar): void, string}>
     */
    public static function clashes(): iterable
    {
        yield 'service' => [
            static fn (ModuleRegistrar $r) => $r->services()->autowire(stdClass::class),
            'Service "stdClass" is registered twice: by module "first" and by module "second".',
        ];
        yield 'route name' => [
            static fn (ModuleRegistrar $r) => $r->routes()->add(new Route('x.show', '/x/' . bin2hex(random_bytes(4)), CallbackHandler::class)),
            'Route "x.show" is registered twice: by module "first" and by module "second".',
        ];
        yield 'block type' => [
            static fn (ModuleRegistrar $r) => $r->blockTypes()->add(ExampleBlockType::class),
            'Block type "' . ExampleBlockType::class . '" is registered twice: by module "first" and by module "second".',
        ];
        yield 'permission' => [
            static fn (ModuleRegistrar $r) => $r->permissions()->add(new Permission('x.manage', 'Manage')),
            'Permission "x.manage" is registered twice: by module "first" and by module "second".',
        ];
        yield 'migration namespace' => [
            static fn (ModuleRegistrar $r) => $r->migrations()->add(new ExampleMigrations('X\Migrations', __DIR__)),
            'Migration namespace "X\Migrations" is registered twice: by module "first" and by module "second".',
        ];
        yield 'admin menu entry' => [
            static fn (ModuleRegistrar $r) => $r->adminMenu()->add(new AdminMenuItem('x', 'X', 'x.show')),
            'Admin menu entry "x" is registered twice: by module "first" and by module "second".',
        ];
        yield 'view namespace' => [
            static fn (ModuleRegistrar $r) => $r->views()->add('x', __DIR__),
            'View namespace "x" is registered twice: by module "first" and by module "second".',
        ];
        yield 'command' => [
            static fn (ModuleRegistrar $r) => $r->commands()->add(ExampleCommand::class),
            'Command "' . ExampleCommand::class . '" is registered twice: by module "first" and by module "second".',
        ];
        yield 'setting' => [
            static fn (ModuleRegistrar $r) => $r->settings()->add(new Setting('x.size', 'Size', SettingType::Integer, 1)),
            'Setting "x.size" is registered twice: by module "first" and by module "second".',
        ];
    }

    /**
     * @param Closure(ModuleRegistrar): void $register
     */
    #[DataProvider('clashes')]
    public function testTwoModulesCantRegisterTheSameThing(Closure $register, string $message): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage($message);

        $this->registry(new FakeModule('first', register: $register), new FakeModule('second', register: $register));
    }

    public function testTwoRoutesCantAnswerTheSameMethodAndPath(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Route "b.list" of module "second" answers GET /list, like route "a.list" of module "first".',
        );

        $this->registry(
            new FakeModule('first', register: static fn (ModuleRegistrar $r) => $r->routes()->add(
                new Route('a.list', '/list', CallbackHandler::class, ['GET', 'HEAD']),
            )),
            new FakeModule('second', register: static fn (ModuleRegistrar $r) => $r->routes()->add(
                new Route('b.list', '/list', CallbackHandler::class, ['POST', 'GET']),
            )),
        );
    }

    public function testListenersMayBeRegisteredByManyModules(): void
    {
        $listen = static fn (ModuleRegistrar $r) => $r->subscribers()->listen(ExampleEvent::class, RecordingListener::class);

        $registry = $this->registry(new FakeModule('first', register: $listen), new FakeModule('second', register: $listen));

        self::assertSame(['first', 'second'], array_map(static fn (Registered $r) => $r->module, $registry->listeners()));
    }

    public function testInvalidValuesFromAModuleNameTheModule(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessageMatches('/^Module "acme.events" could not register: Permission name /');

        $this->registry(new FakeModule('acme.events', register: static fn (ModuleRegistrar $r) => $r->permissions()->add(
            new Permission('Not Valid', 'Label'),
        )));
    }

    /**
     * @return iterable<string, array{Closure(ModuleRegistrar): void, string}>
     */
    public static function invalidRegistrations(): iterable
    {
        yield 'service class does not exist' => [
            static fn (ModuleRegistrar $r) => $r->services()->autowire('Acme\Missing'), // @phpstan-ignore argument.type (deliberately invalid)
            'Module "acme" registers service class Acme\Missing, which does not exist.',
        ];
        yield 'service without id' => [
            static fn (ModuleRegistrar $r) => $r->services()->factory(' ', static fn (): stdClass => new stdClass()),
            'Module "acme" registers a service without an id.',
        ];
        yield 'block type is not a block type' => [
            static fn (ModuleRegistrar $r) => $r->blockTypes()->add(stdClass::class), // @phpstan-ignore argument.type (deliberately invalid)
            'Module "acme" registers block type stdClass, which is not a class that implements SightPress\Module\Block\BlockType.',
        ];
        yield 'migration namespace is invalid' => [
            static fn (ModuleRegistrar $r) => $r->migrations()->add(new ExampleMigrations('Acme\\\\Bad-Name', __DIR__)),
            'Module "acme" registers migrations in "Acme\\\\Bad-Name", which is not a valid PHP namespace.',
        ];
        yield 'migration folder is missing' => [
            static fn (ModuleRegistrar $r) => $r->migrations()->add(new ExampleMigrations('Acme\Migrations', '/nowhere')),
            'Module "acme" registers migrations in /nowhere, which is not a folder.',
        ];
        yield 'view namespace is invalid' => [
            static fn (ModuleRegistrar $r) => $r->views()->add('Acme', __DIR__),
            'Module "acme" registers views under "Acme": use lower-case letters, digits and "_".',
        ];
        yield 'view folder is relative' => [
            static fn (ModuleRegistrar $r) => $r->views()->add('acme', 'templates'),
            'Module "acme" registers views in templates, which is not an absolute path to a folder.',
        ];
        yield 'view folder is missing' => [
            static fn (ModuleRegistrar $r) => $r->views()->add('acme', '/nowhere'),
            'Module "acme" registers views in /nowhere, which is not an absolute path to a folder.',
        ];
        yield 'event does not exist' => [
            static fn (ModuleRegistrar $r) => $r->subscribers()->listen('Acme\Missing', RecordingListener::class), // @phpstan-ignore argument.type (deliberately invalid)
            'Module "acme" listens to event Acme\Missing, which is not a class or interface.',
        ];
        yield 'listener is not invokable' => [
            static fn (ModuleRegistrar $r) => $r->subscribers()->listen(\Stringable::class, stdClass::class),
            'Module "acme" registers listener stdClass, which is not an invokable class.',
        ];
        yield 'command is not a command' => [
            static fn (ModuleRegistrar $r) => $r->commands()->add(stdClass::class), // @phpstan-ignore argument.type (deliberately invalid)
            'Module "acme" registers command stdClass, which is not a class that implements SightPress\Module\Console\Command.',
        ];
    }

    /**
     * @param Closure(ModuleRegistrar): void $register
     */
    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrationsSayWhatIsWrong(Closure $register, string $message): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage($message);

        $this->registry(new FakeModule('acme', register: $register));
    }

    public function testAModuleMayOnlyUsePublicServicesOfModulesItRequires(): void
    {
        $registry = $this->registry(
            new FakeModule('media', register: static function (ModuleRegistrar $r): void {
                $r->services()->factory('media.public', static fn (): stdClass => new stdClass(), public: true);
                $r->services()->factory('media.private', static fn (): stdClass => new stdClass());
            }),
            new FakeModule('gallery', ['media' => '*']),
            new FakeModule('blog'),
        );

        self::assertTrue($registry->canUse('media', 'media.private'), 'own service');
        self::assertTrue($registry->canUse('blog', 'core.service'), 'core service');
        self::assertTrue($registry->canUse('gallery', 'media.public'), 'public service of a required module');
        self::assertFalse($registry->canUse('gallery', 'media.private'), 'private service');
        self::assertFalse($registry->canUse('blog', 'media.public'), 'module not required');
        self::assertFalse($registry->canUse('unknown', 'media.public'), 'unknown module');
        $registry->assertCanUse('gallery', 'media.public');

        try {
            $registry->assertCanUse('gallery', 'media.private');
            self::fail('A private service was handed out.');
        } catch (ModuleException $exception) {
            self::assertSame('Module "gallery" uses service "media.private", which is private to module "media".', $exception->getMessage());
        }

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage(
            'Module "blog" uses service "media.public" of module "media" but does not require that module. '
            . 'Add "media" to the requirements in its manifest.',
        );

        $registry->assertCanUse('blog', 'media.public');
    }

    private function registry(FakeModule ...$modules): ModuleRegistry
    {
        $candidates = array_map(
            static fn (FakeModule $module): ModuleCandidate => new ModuleCandidate($module, 'sightpress/core'),
            array_values($modules),
        );

        return ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue($candidates)->resolve(new ModuleSelection()));
    }
}
