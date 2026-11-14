<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Csrf;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Session\Session;

/**
 * Checks the CSRF token of every state-changing request (`POST`, `PUT`, `PATCH`, `DELETE`); a missing or wrong token
 * answers 403 with a page that explains the form expired. Every request gets {@see CsrfTokens} for its forms.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    private const array STATE_CHANGING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $tokens = new CsrfTokens(Session::fromRequest($request), $this->responses);
        $request = $request->withAttribute(CsrfTokens::class, $tokens);

        if (in_array(strtoupper($request->getMethod()), self::STATE_CHANGING, true)) {
            return $tokens->guard()->process($request, $handler);
        }

        return $handler->handle($request);
    }
}
