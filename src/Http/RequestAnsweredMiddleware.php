<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Contract\AnsweredResource;
use SightPress\Core\Http\Contract\RequestAnswered;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\SiteContext;
use Slim\Exception\HttpNotFoundException;

/**
 * Puts an {@see AnsweredResource} on every request to a site and, once the response has been sent
 * ({@see AfterResponse}), dispatches {@see RequestAnswered} on the site's event dispatcher. Runs after the visitor is
 * known, so listeners know whether they were signed in. "Not found" is told as a 404 even though it is thrown.
 */
final readonly class RequestAnsweredMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AfterResponse $after,
        private ClockInterface $clock,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = SiteContext::fromRequest($request);
        if ($site === null) {
            return $handler->handle($request);
        }
        $resource = new AnsweredResource();
        $request = $request->withAttribute(AnsweredResource::class, $resource);
        $at = $this->clock->now();
        try {
            $response = $handler->handle($request);
        } catch (HttpNotFoundException $exception) {
            $this->tell($site, $request, 404, $resource, $at);

            throw $exception;
        }
        $this->tell($site, $request, $response->getStatusCode(), $resource, $at);

        return $response;
    }

    private function tell(SiteContext $site, ServerRequestInterface $request, int $status, AnsweredResource $resource, DateTimeImmutable $at): void
    {
        $signedIn = Actor::fromRequest($request)->isSignedIn();
        $this->after->add(static function () use ($site, $request, $status, $resource, $signedIn, $at): void {
            Services::get($site->container, EventDispatcherInterface::class)
                ->dispatch(new RequestAnswered($request, $status, $resource, $signedIn, $at));
        });
    }
}
