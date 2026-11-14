<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Error\HttpServiceUnavailableException;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use Slim\Exception\HttpGoneException;
use Slim\Exception\HttpNotFoundException;

/**
 * Finds the site a request is for from its host name (ADR-0003) and puts its {@see SiteContext} on the request.
 *
 * Unknown host or deleted site → 404; disabled → 503 with `Retry-After`; archived → 410. Only enabled sites get a
 * site container.
 */
final readonly class SiteResolverMiddleware implements MiddlewareInterface
{
    /** How long a visitor is asked to wait before trying a disabled site again, in seconds. */
    public const int RETRY_AFTER = 3600;

    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $this->sites->findByDomain($request->getUri()->getHost());

        return match ($site?->status) {
            SiteStatus::Enabled => $handler->handle($request->withAttribute(
                SiteContext::class,
                new SiteContext($site, $this->containers->forSite($site)),
            )),
            SiteStatus::Disabled => throw new HttpServiceUnavailableException(
                $request,
                'This site is not available at the moment.',
                self::RETRY_AFTER,
            ),
            SiteStatus::Archived => throw new HttpGoneException($request),
            SiteStatus::Deleted, null => throw new HttpNotFoundException($request),
        };
    }
}
