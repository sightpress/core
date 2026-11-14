<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Tenant\SiteRepository;
use Slim\Exception\HttpNotFoundException;

/**
 * On an install without any site, turns "Page not found" into the "No site configured" page, so people setting
 * up SightPress know what to do next.
 */
final readonly class NoSiteConfiguredMiddleware implements MiddlewareInterface
{
    public function __construct(private SiteRepository $sites)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpNotFoundException $exception) {
            if ($exception instanceof NoSiteConfiguredException || $this->sites->all() !== []) {
                throw $exception;
            }

            throw new NoSiteConfiguredException($request, null, $exception);
        }
    }
}
