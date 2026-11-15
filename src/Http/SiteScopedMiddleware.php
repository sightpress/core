<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContext;

/**
 * A route's middleware, built from the container of the site the request is for; without a site, from the tenant
 * container.
 */
final readonly class SiteScopedMiddleware implements MiddlewareInterface
{
    /**
     * @param class-string<MiddlewareInterface> $middleware
     */
    public function __construct(
        private string $middleware,
        private ContainerInterface $tenant,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = SiteContext::fromRequest($request)->container ?? $this->tenant;

        return Services::get($container, $this->middleware)->process($request, $handler);
    }
}
