<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Error\NoAdminAccessException;
use SightPress\Core\Http\RoutePermissionMiddleware;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tests\Support\CallbackHandler;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(RoutePermissionMiddleware::class)]
#[UsesClass(Actor::class)]
#[UsesClass(NoAdminAccessException::class)]
final class RoutePermissionMiddlewareTest extends TestCase
{
    public function testSignedOutVisitorsGoToTheSignInPageAndBack(): void
    {
        $response = $this->middleware()->process(new ServerRequest('GET', 'https://example.org/admin/events?page=2'), $this->handler());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login?return=%2Fadmin%2Fevents%3Fpage%3D2', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('/login?return=%2Fevents', $this->middleware()->process(new ServerRequest('GET', '/events'), $this->handler())->getHeaderLine('Location'));
    }

    public function testAdminScreensAlsoNeedAdminAccess(): void
    {
        $this->expectException(NoAdminAccessException::class);

        $this->middleware()->process($this->as(['events.manage'], '/admin/events'), $this->handler());
    }

    public function testTheAdminHomeAlsoNeedsAdminAccess(): void
    {
        $this->expectException(NoAdminAccessException::class);

        $this->middleware()->process($this->as(['events.manage'], '/admin'), $this->handler());
    }

    public function testWithoutThePermissionTheAnswerIsNoAccess(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->middleware()->process($this->as(['admin.access'], '/admin/events'), $this->handler());
    }

    public function testWithThePermissionTheRouteRuns(): void
    {
        self::assertSame(204, $this->middleware()->process($this->as(['admin.access', 'events.manage'], '/admin/events'), $this->handler())->getStatusCode());
        self::assertSame(204, $this->middleware()->process($this->as(['events.manage'], '/administrators'), $this->handler())->getStatusCode(), 'not an admin path');
    }

    private function middleware(): RoutePermissionMiddleware
    {
        return new RoutePermissionMiddleware('events.manage', new Psr17Factory());
    }

    /**
     * @param list<string> $permissions
     */
    private function as(array $permissions, string $path): ServerRequest
    {
        return new ServerRequest('GET', $path)->withAttribute(Actor::class, new Actor(5, $permissions));
    }

    private function handler(): CallbackHandler
    {
        return new CallbackHandler(static fn () => new Psr17Factory()->createResponse(204));
    }
}
