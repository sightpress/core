<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /_sightpress/request-too-large`: where the web server sends a request it refused for its size (nginx
 * `error_page 413`), so the person sees the same accessible page as when PHP refuses it, not the web server's own.
 */
final readonly class RequestTooLargeHandler implements RequestHandlerInterface
{
    public const string PATH = '/_sightpress/request-too-large';

    public function __construct(private ServerLimits $limits)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new RequestTooLargeException($request, $this->limits->requestLimit(), RequestTooLargeException::backFrom($request));
    }
}
