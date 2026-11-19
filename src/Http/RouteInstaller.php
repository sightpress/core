<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Psr\Container\ContainerInterface;
use SightPress\Core\Module\ModulePolicy;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\Registered;
use Slim\Interfaces\RouteCollectorProxyInterface;

/**
 * Adds the routes modules registered to the router. Handlers and middleware are built when a request needs them,
 * from the site's container (or the tenant's when there is no site); a route's middleware runs in the order the
 * module listed it. A route of a module that is off for the request's site answers 404 ({@see ModuleGate}).
 *
 * Routes whose path starts with a placeholder (`/{path:.+}`, the public pages) catch almost any address, so they are
 * added after all the others, whatever order the modules registered in: `/media/…` or `/admin/…` always win.
 */
final readonly class RouteInstaller
{
    /**
     * @param ModulePolicy|null $policy which modules are optional: only their routes are checked per site, so the
     *                                  routes of required modules never read the site's choices; null checks all
     */
    public function __construct(
        private ContainerInterface $tenant,
        private ?ModulePolicy $policy = null,
    ) {
    }

    /**
     * @template TContainer of \Psr\Container\ContainerInterface|null
     *
     * @param RouteCollectorProxyInterface<TContainer> $router
     */
    public function install(RouteCollectorProxyInterface $router, ModuleRegistry $registry): void
    {
        $routes = $registry->routes();
        usort($routes, static fn (Registered $a, Registered $b): int => self::catchesAll($a->item->path) <=> self::catchesAll($b->item->path));
        foreach ($routes as $registered) {
            $route = $registered->item;
            $module = $this->policy?->catalogue()->get($registered->module)->manifest->required === true ? null : $registered->module;
            $slimRoute = $router
                ->map($route->methods, $route->path, new SiteScopedHandler($route->handler, $this->tenant, $module))
                ->setName($route->name);
            // Slim runs the middleware added last first.
            foreach (array_reverse($route->middleware) as $middleware) {
                $slimRoute->add(new SiteScopedMiddleware($middleware, $this->tenant, $module));
            }
        }
    }

    private static function catchesAll(string $path): bool
    {
        return str_starts_with($path, '/{');
    }
}
