<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Dashboard;

use LogicException;
use Psr\Container\ContainerInterface;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Dashboard\DashboardWidget;

/**
 * The dashboard widgets the site's modules that are on added (`ModuleRegistrar::dashboard()`), built from the
 * site's container, and the paths of the routes widgets link to. Shared code, so the admin module can show them
 * without reaching into the module loader (ADR-0009).
 */
final class DashboardWidgets
{
    /** @var list<DashboardWidget>|null */
    private ?array $widgets = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ModuleRegistry $registry,
    ) {
    }

    /**
     * Every widget, by position and then by title.
     *
     * @return list<DashboardWidget>
     *
     * @throws LogicException when the container builds something that isn't a widget
     */
    public function all(): array
    {
        if ($this->widgets !== null) {
            return $this->widgets;
        }
        $widgets = [];
        foreach ($this->registry->dashboardWidgets() as $registered) {
            $widget = $this->container->get($registered->item);
            if (!$widget instanceof DashboardWidget) {
                throw new LogicException(sprintf('Dashboard widget %s of module "%s" is not a %s.', $registered->item, $registered->module, DashboardWidget::class));
            }
            $widgets[] = $widget;
        }
        usort($widgets, static fn (DashboardWidget $a, DashboardWidget $b): int => [$a->position(), $a->title()] <=> [$b->position(), $b->title()]);

        return $this->widgets = $widgets;
    }

    /**
     * The site-relative path of a named route, with its placeholders filled in (URL-encoded).
     *
     * @param array<string, string> $parameters
     *
     * @return non-empty-string
     *
     * @throws LogicException for a route no module that is on registered, or a placeholder without a value
     */
    public function url(string $route, array $parameters = []): string
    {
        foreach ($this->registry->routes() as $registered) {
            if ($registered->item->name !== $route) {
                continue;
            }
            $path = (string) preg_replace_callback('/\{(\w+)(?::[^{}]*(?:\{[^{}]*\}[^{}]*)*)?\}/', static function (array $match) use ($parameters, $route): string {
                return isset($parameters[$match[1]]) ? rawurlencode($parameters[$match[1]]) : throw new LogicException(sprintf('The route "%s" needs a value for "%s".', $route, $match[1]));
            }, $registered->item->path);

            return $path === '' ? '/' : $path;
        }

        throw new LogicException(sprintf('No module that is on registered the route "%s".', $route));
    }
}
