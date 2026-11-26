<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\StepUp\StepUpGate;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Tenant\SiteContext;
use Slim\Routing\RouteContext;

/**
 * Two-step confirmation for critical actions (N-6, ADR-0073): after routing and the CSRF check, a change (`POST`,
 * `PUT`, `PATCH`, `DELETE`) to a critical route — core's list, or a module route marked `critical` — goes to the
 * site's {@see StepUpGate}, which holds it until the person has typed a code.
 */
final readonly class StepUpMiddleware implements MiddlewareInterface
{
    private const array CHANGES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** The module that registers {@see StepUpGate}. */
    private const string AUTH = 'auth';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = SiteContext::fromRequest($request)?->container;
        $route = $request->getAttribute(RouteContext::ROUTE);
        if (
            $container === null
            || !$route instanceof \Slim\Interfaces\RouteInterface
            || !in_array(strtoupper($request->getMethod()), self::CHANGES, true)
            || !$container->has(ModuleRegistry::class)
        ) {
            return $handler->handle($request);
        }
        $registry = Services::get($container, ModuleRegistry::class);
        // PHP-DI can build any class, so ask the registry whether the module that holds the gate is loaded.
        if (!$registry->has(self::AUTH)) {
            return $handler->handle($request);
        }
        $name = $route->getName();
        $flagged = false;
        foreach ($registry->routes() as $registered) {
            if ($registered->item->name === $name) {
                $flagged = $registered->item->critical;
            }
        }
        $gate = Services::get($container, StepUpGate::class);

        return $gate->needsConfirmation($request, $name, $flagged) ? $gate->hold($request) : $handler->handle($request);
    }
}
