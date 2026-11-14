<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac\Contract;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Error\NoAdminAccessException;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Core\Tests\Support\CallbackHandler;

#[CoversClass(AdminAccessMiddleware::class)]
#[UsesClass(Actor::class)]
#[UsesClass(NoAdminAccessException::class)]
final class AdminAccessMiddlewareTest extends TestCase
{
    public function testAnonymousVisitorsAreSentToSignInAndBack(): void
    {
        $handler = $this->handler();

        $response = $this->middleware()->process(new ServerRequest('GET', 'https://demo.example/admin/pages?sort=title'), $handler);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login?return=%2Fadmin%2Fpages%3Fsort%3Dtitle', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertNull($handler->request);

        $plain = $this->middleware()->process(new ServerRequest('GET', '/admin'), $handler);
        self::assertSame('/login?return=%2Fadmin', $plain->getHeaderLine('Location'));
    }

    public function testPeopleWithoutAdminAccessGetA403(): void
    {
        $request = new ServerRequest('GET', '/admin')->withAttribute(Actor::class, new Actor(5));

        $this->expectException(NoAdminAccessException::class);

        $this->middleware()->process($request, $this->handler());
    }

    public function testPeopleWithAdminAccessGetThrough(): void
    {
        $handler = $this->handler();
        $request = new ServerRequest('GET', '/admin')->withAttribute(Actor::class, new Actor(5, ['admin.access']));

        $response = $this->middleware()->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($request, $handler->request);
    }

    private function middleware(): AdminAccessMiddleware
    {
        return new AdminAccessMiddleware(new Psr17Factory());
    }

    private function handler(): CallbackHandler
    {
        return new CallbackHandler(static fn (): Response => new Response(200));
    }
}
