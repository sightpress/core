<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContext;

/**
 * A route's handler, built from the container of the site the request is for (ADR-0003), so it gets that site's
 * services; without a site, from the tenant container. The router calls it like a function.
 */
final readonly class SiteScopedHandler implements RequestHandlerInterface
{
    /**
     * @param class-string<RequestHandlerInterface> $handler
     * @param string|null                           $module  the module that registered the route: when it is off for the
     *                                                       request's site, the route answers 404 (ADR-0034)
     */
    public function __construct(
        private string $handler,
        private ContainerInterface $tenant,
        private ?string $module = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $container = SiteContext::fromRequest($request)->container ?? $this->tenant;
        ModuleGate::check($request, $this->module);

        return Services::get($container, $this->handler)->handle($request);
    }
}
