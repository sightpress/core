<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Psr\Container\ContainerInterface;
use SightPress\Core\Module\ModuleRegistry;
use Slim\Interfaces\RouteCollectorProxyInterface;

/**
 * Adds the routes modules registered to the router. Handlers and middleware are built when a request needs them,
 * from the site's container (or the tenant's when there is no site); a route's middleware runs in the order the
 * module listed it.
 */
final readonly class RouteInstaller
{
    public function __construct(private ContainerInterface $tenant)
    {
    }

    /**
     * @template TContainer of \Psr\Container\ContainerInterface|null
     *
     * @param RouteCollectorProxyInterface<TContainer> $router
     */
    public function install(RouteCollectorProxyInterface $router, ModuleRegistry $registry): void
    {
        foreach ($registry->routes() as $registered) {
            $route = $registered->item;
            $slimRoute = $router
                ->map($route->methods, $route->path, new SiteScopedHandler($route->handler, $this->tenant))
                ->setName($route->name);
            // Slim runs the middleware added last first.
            foreach (array_reverse($route->middleware) as $middleware) {
                $slimRoute->add(new SiteScopedMiddleware($middleware, $this->tenant));
            }
        }
    }
}
