<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http;

use DI\Container;
use Monolog\Logger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Http\AfterResponse;
use SightPress\Core\Http\Contract\AnsweredResource;
use SightPress\Core\Http\Contract\RequestAnswered;
use SightPress\Core\Http\RequestAnsweredMiddleware;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\SiteFixture;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(RequestAnsweredMiddleware::class)]
#[CoversClass(RequestAnswered::class)]
#[UsesNamespace('SightPress\Core')]
final class RequestAnsweredMiddlewareTest extends TestCase
{
    public function testTellsTheSiteAboutTheRequestOnlyAfterTheResponse(): void
    {
        $events = new RecordingEvents();
        $after = new AfterResponse(new Logger('test'));
        $middleware = new RequestAnsweredMiddleware($after, new FixedClock());
        $request = new ServerRequest('GET', 'https://demo.example/about')
            ->withAttribute(SiteContext::class, new SiteContext(SiteFixture::site(), new Container([EventDispatcherInterface::class => $events])))
            ->withAttribute(Actor::class, new Actor(5, []));

        $response = $middleware->process($request, new CallbackHandler(static function ($request): Response {
            AnsweredResource::fromRequest($request)?->page(3, 'en-GB');

            return new Response(200);
        }));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $events->events, 'not before the response is sent');

        $after->run();
        self::assertCount(1, $events->events);
        $answered = $events->events[0];
        self::assertInstanceOf(RequestAnswered::class, $answered);
        self::assertSame([200, 'page', 3, true, '2026-11-14 12:00:00'], [$answered->status, $answered->resource->kind(), $answered->resource->pageId(), $answered->signedIn, $answered->at->format('Y-m-d H:i:s')]);
        self::assertSame('/about', $answered->request->getUri()->getPath());
    }

    public function testNotFoundIsToldAsA404AndStillThrown(): void
    {
        $events = new RecordingEvents();
        $after = new AfterResponse(new Logger('test'));
        $request = new ServerRequest('GET', 'https://demo.example/nothing')
            ->withAttribute(SiteContext::class, new SiteContext(SiteFixture::site(), new Container([EventDispatcherInterface::class => $events])));

        try {
            new RequestAnsweredMiddleware($after, new FixedClock())->process($request, new CallbackHandler(static fn ($request): Response => throw new HttpNotFoundException($request)));
            self::fail('not thrown');
        } catch (HttpNotFoundException) {
        }
        $after->run();

        self::assertInstanceOf(RequestAnswered::class, $events->events[0]);
        self::assertSame([404, null, false], [$events->events[0]->status, $events->events[0]->resource->kind(), $events->events[0]->signedIn]);
    }

    public function testRequestsWithoutASiteAreLeftAlone(): void
    {
        $after = new AfterResponse(new Logger('test'));
        $handler = new CallbackHandler(static fn (): Response => new Response(204));

        self::assertSame(204, new RequestAnsweredMiddleware($after, new FixedClock())->process(new ServerRequest('GET', '/'), $handler)->getStatusCode());
        self::assertNull($handler->request?->getAttribute(AnsweredResource::class));
    }
}
