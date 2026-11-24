<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Error\NoAdminAccessException;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use Slim\Exception\HttpForbiddenException;

/**
 * Checks a route's `permission` (module-interface `Route::$permission`, ADR-0062) before its handler and middleware
 * run: a signed-out visitor goes to the sign-in page and comes back, an admin screen also needs `admin.access`, and
 * anyone else without the permission gets the "no access" page.
 */
final readonly class RoutePermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $permission,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->isSignedIn()) {
            $uri = $request->getUri();
            $return = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());

            return $this->responses->createResponse(303)
                ->withHeader('Location', AdminAccessMiddleware::SIGN_IN_PATH . '?' . http_build_query(['return' => $return]))
                ->withHeader('Cache-Control', 'no-store');
        }
        $admin = $request->getUri()->getPath() === '/admin' || str_starts_with($request->getUri()->getPath(), '/admin/');
        if ($admin && !$actor->has(AdminAccessMiddleware::PERMISSION)) {
            throw new NoAdminAccessException($request);
        }
        if (!$actor->has($this->permission)) {
            throw new HttpForbiddenException($request);
        }

        return $handler->handle($request);
    }
}
