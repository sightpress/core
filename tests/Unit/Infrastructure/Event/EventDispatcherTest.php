<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Event;

use DI\Container;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;
use SightPress\Core\Infrastructure\Event\EventDispatcher;
use SightPress\Core\Infrastructure\Event\ListenerProvider;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExampleEvent;
use SightPress\Core\Tests\Support\Modules\RecordingListener;
use SightPress\Module\ModuleRegistrar;
use stdClass;

#[CoversClass(EventDispatcher::class)]
#[CoversClass(ListenerProvider::class)]
#[UsesNamespace('SightPress\Core\Module')]
final class EventDispatcherTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        RecordingListener::$log = [];
        $this->container = new Container();
        $this->container->set(LowListener::class, new RecordingListener('low'));
        $this->container->set(HighListener::class, new RecordingListener('high'));
        $this->container->set(AnyListener::class, new RecordingListener('any'));
        $this->container->set(BrokenListener::class, new stdClass());
    }

    public function testListenersRunByPriorityThenRegistrationOrder(): void
    {
        $dispatcher = $this->dispatcher(static function (ModuleRegistrar $r): void {
            $r->subscribers()->listen(ExampleEvent::class, RecordingListener::class);
            $r->subscribers()->listen(ExampleEvent::class, LowListener::class, -5);
            $r->subscribers()->listen(ExampleEvent::class, HighListener::class, 5);
            $r->subscribers()->listen(\Psr\EventDispatcher\StoppableEventInterface::class, AnyListener::class);
            $r->subscribers()->listen(stdClass::class, BrokenListener::class);
        });

        $event = new ExampleEvent();

        self::assertSame($event, $dispatcher->dispatch($event));
        self::assertSame(
            ['high:' . ExampleEvent::class, 'recording:' . ExampleEvent::class, 'any:' . ExampleEvent::class, 'low:' . ExampleEvent::class],
            RecordingListener::$log,
        );
    }

    public function testAStoppedEventReachesNoFurtherListeners(): void
    {
        $dispatcher = $this->dispatcher(static function (ModuleRegistrar $r): void {
            $r->subscribers()->listen(ExampleEvent::class, HighListener::class, 5);
            $r->subscribers()->listen(ExampleEvent::class, LowListener::class);
        });

        $dispatcher->dispatch(new ExampleEvent(stopAfterFirst: true));

        self::assertSame(['high:' . ExampleEvent::class], RecordingListener::$log);
    }

    public function testAListenerMustBeInvokable(): void
    {
        $dispatcher = $this->dispatcher(static function (ModuleRegistrar $r): void {
            $r->subscribers()->listen(stdClass::class, BrokenListener::class);
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Listener ' . BrokenListener::class . ' of module "acme" is not invokable.');

        $dispatcher->dispatch(new stdClass());
    }

    public function testTheDispatcherOnlyCallsCallables(): void
    {
        $provider = new class () implements ListenerProviderInterface {
            /**
             * @return iterable<mixed>
             */
            public function getListenersForEvent(object $event): iterable
            {
                return ['not callable'];
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The listener provider returned string, not a callable.');

        new EventDispatcher($provider)->dispatch(new stdClass());
    }

    /**
     * @param \Closure(ModuleRegistrar): void $register
     */
    private function dispatcher(\Closure $register): EventDispatcher
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme', register: $register), 'sightpress/core'),
        ])->resolve(new ModuleSelection()));

        return new EventDispatcher(new ListenerProvider($this->container, $registry));
    }
}

final class LowListener
{
    public function __invoke(object $event): void
    {
    }
}

final class HighListener
{
    public function __invoke(object $event): void
    {
    }
}

final class AnyListener
{
    public function __invoke(object $event): void
    {
    }
}

final class BrokenListener
{
    public function __invoke(object $event): void
    {
    }
}
