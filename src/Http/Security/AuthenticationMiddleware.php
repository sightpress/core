<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContext;

/**
 * Asks the site's {@see Authenticator} who is making the request. Requests without a site, or to a site where no
 * module provides one, stay anonymous.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = SiteContext::fromRequest($request)?->container;
        if ($container === null || !$container->has(Authenticator::class)) {
            return $handler->handle($request);
        }

        $authenticator = Services::get($container, Authenticator::class);
        $request = $authenticator->authenticate($request);

        return $authenticator->respond($request, $handler->handle($request));
    }
}
