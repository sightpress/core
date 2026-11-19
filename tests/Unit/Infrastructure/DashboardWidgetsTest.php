<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Dashboard\DashboardWidgets;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\Dashboard\FakeWidgets;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Module\ModuleRegistrar;

#[CoversClass(DashboardWidgets::class)]
#[CoversClass(\SightPress\Core\Admin\Http\AdminDashboardContext::class)]
#[UsesNamespace('SightPress\Core\Module')]
#[UsesNamespace('SightPress\Core\Rbac')]
#[UsesNamespace('SightPress\Core\Template')]
final class DashboardWidgetsTest extends TestCase
{
    public function testBuildsTheWidgetsInOrderAndTheAddressesOfRoutes(): void
    {
        $widgets = FakeWidgets::widgets();

        self::assertSame(['acme.money', 'acme.broken', 'acme.news'], array_map(static fn ($widget): string => $widget->id(), $widgets->all()));
        self::assertSame($widgets->all(), $widgets->all(), 'built once');
        self::assertSame('/admin/news/a%20b', $widgets->url('acme.news.item', ['id' => 'a b']));
        self::assertSame('/', $widgets->url('acme.news.home'));
    }

    public function testTheContextSaysWhoIsLooking(): void
    {
        $context = new \SightPress\Core\Admin\Http\AdminDashboardContext(new \SightPress\Core\Template\Viewer('pt-PT', 'Europe/Lisbon'), new \SightPress\Core\Rbac\Contract\Actor(3, ['acme.money']), FakeWidgets::widgets());

        self::assertSame(['pt-PT', 'Europe/Lisbon', true, false, '/admin/news/9'], [$context->locale(), $context->timeZone()->getName(), $context->can('acme.money'), $context->can('acme.other'), $context->url('acme.news.item', ['id' => '9'])]);
    }

    public function testRoutesItCannotFillAreRefused(): void
    {
        $widgets = FakeWidgets::widgets();

        foreach ([['acme.news.item', 'The route "acme.news.item" needs a value for "id".'], ['nowhere', 'No module that is on registered the route "nowhere".']] as [$route, $message]) {
            try {
                $widgets->url($route);
                self::fail($route);
            } catch (LogicException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testAServiceThatIsNotAWidgetIsRefused(): void
    {
        $register = static fn (ModuleRegistrar $registrar) => $registrar->dashboard()->add(\SightPress\Core\Tests\Support\Dashboard\NewsWidget::class);
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate(new FakeModule('acme.news', register: $register), 'acme/news', '*')])->resolve(new ModuleSelection()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/is not a SightPress\\\\Module\\\\Dashboard\\\\DashboardWidget/');
        new DashboardWidgets(new ArrayContainer([\SightPress\Core\Tests\Support\Dashboard\NewsWidget::class => new \stdClass()]), $registry)->all();
    }
}
