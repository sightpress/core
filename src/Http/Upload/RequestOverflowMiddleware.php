<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs before the CSRF check. When a request is larger than PHP's `post_max_size`, PHP drops every field and file,
 * the CSRF token too, so the person would see "This form has expired". Instead they see a 413 page that says how
 * much the server accepts, with a link back to the form ({@see RequestTooLargeException}).
 */
final readonly class RequestOverflowMiddleware implements MiddlewareInterface
{
    public function __construct(private ServerLimits $limits)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $length = $request->getHeaderLine('Content-Length');
        if (ctype_digit($length) && $this->limits->overflows((int) $length)) {
            throw new RequestTooLargeException($request, $this->limits->requestLimit(), RequestTooLargeException::backFrom($request));
        }

        return $handler->handle($request);
    }
}
