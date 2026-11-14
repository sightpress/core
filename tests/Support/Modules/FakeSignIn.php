<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Rbac\Contract\Actor;

/**
 * Stands in for sign-in (C-3.3): the `X-Test-User` header picks a member or an editor.
 */
final class FakeSignIn implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actor = match ($request->getHeaderLine('X-Test-User')) {
            'member' => new Actor(1),
            'editor' => new Actor(2, ['admin.access', 'page.publish']),
            default => Actor::anonymous(),
        };

        return $handler->handle($request->withAttribute(Actor::class, $actor));
    }
}
