<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Error\NoAdminAccessException;

/**
 * Guards every `/admin` route (D24): anonymous visitors are sent to sign in and brought back afterwards; signed-in
 * people without `admin.access` (every `member`) get a 403 page with links to the site and to *My account*.
 */
final readonly class AdminAccessMiddleware implements MiddlewareInterface
{
    public const string PERMISSION = 'admin.access';
    public const string SIGN_IN_PATH = '/login';

    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->isSignedIn()) {
            $uri = $request->getUri();
            $return = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());

            return $this->responses->createResponse(303)
                ->withHeader('Location', self::SIGN_IN_PATH . '?' . http_build_query(['return' => $return]))
                ->withHeader('Cache-Control', 'no-store');
        }
        if (!$actor->has(self::PERMISSION)) {
            throw new NoAdminAccessException($request);
        }

        return $handler->handle($request);
    }
}
