<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Catches every exception from the inner middleware and handlers and answers with an error page.
 */
final readonly class ErrorHandlingMiddleware implements MiddlewareInterface
{
    public function __construct(private ErrorResponder $responder)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            return $this->responder->respond($request, $exception);
        }
    }
}
