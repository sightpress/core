<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Dashboard;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use SightPress\Core\Infrastructure\Dashboard\DashboardWidgets;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Module\Dashboard\DashboardWidget;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * Dashboard widgets of a test module `acme.news`: "Latest news" for everyone, "Money" for people with
 * `acme.money`, and "Broken", which fails; with the route `acme.news.item` (`/admin/news/{id:\d+}`).
 */
final class FakeWidgets
{
    public readonly TestHandler $log;

    public function __construct()
    {
        $this->log = new TestHandler();
    }

    public function logger(): Logger
    {
        return new Logger('test', [$this->log]);
    }

    public static function widgets(): DashboardWidgets
    {
        $register = static function (ModuleRegistrar $registrar): void {
            foreach ([NewsWidget::class, MoneyWidget::class, BrokenWidget::class] as $widget) {
                $registrar->dashboard()->add($widget);
            }
            $registrar->routes()->add(new Route('acme.news.item', '/admin/news/{id:\d+}', NewsWidget::class));
            $registrar->routes()->add(new Route('acme.news.home', '/', NewsWidget::class));
        };
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate(new FakeModule('acme.news', register: $register), 'acme/news', '*')])->resolve(new ModuleSelection()));

        return new DashboardWidgets(new ArrayContainer([NewsWidget::class => new NewsWidget(), MoneyWidget::class => new MoneyWidget(), BrokenWidget::class => new BrokenWidget()]), $registry);
    }
}
