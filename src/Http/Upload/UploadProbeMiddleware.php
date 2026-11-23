<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers a correctly signed {@see UploadProbe} and discards its body without reading it. It runs before everything
 * that could refuse the request for another reason (site, rate limit, PHP's `post_max_size`, CSRF), so the answer
 * shows what the web server let through. Any other request carries on, and this address answers 404 like an unknown one.
 */
final readonly class UploadProbeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UploadProbe $probe,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'POST'
            && $request->getUri()->getPath() === UploadProbe::PATH
            && $this->probe->accepts($request->getQueryParams(), $request->getHeaderLine('Content-Length'))) {
            return $this->responses->createResponse(204)->withHeader(UploadProbe::ANSWER_HEADER, 'accepted');
        }

        return $handler->handle($request);
    }
}
