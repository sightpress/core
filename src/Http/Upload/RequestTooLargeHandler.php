<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Media\UploadRefusals;

/**
 * `GET /_sightpress/request-too-large`: where the web server sends a request it refused for its size (nginx
 * `error_page 413`), so the person sees the same accessible page as when PHP refuses it, not the web server's own.
 * The refusal is counted only when the web server says so in {@see REFUSED_PARAM}, a server variable that a visitor
 * can't set, so opening this address doesn't change the count.
 */
final readonly class RequestTooLargeHandler implements RequestHandlerInterface
{
    public const string PATH = '/_sightpress/request-too-large';

    public const string REFUSED_PARAM = 'SIGHTPRESS_BODY_REFUSED';

    public function __construct(
        private ServerLimits $limits,
        private UploadRefusals $refusals,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (($request->getServerParams()[self::REFUSED_PARAM] ?? null) === '1') {
            $this->refusals->record();
        }

        throw new RequestTooLargeException($request, $this->limits->requestLimit(), RequestTooLargeException::backFrom($request));
    }
}
