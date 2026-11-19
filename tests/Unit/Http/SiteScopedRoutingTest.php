<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http;

use DI\Container;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\SiteScopedHandler;
use SightPress\Core\Http\SiteScopedMiddleware;
use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(SiteScopedHandler::class)]
#[CoversClass(SiteScopedMiddleware::class)]
#[CoversClass(\SightPress\Core\Http\ModuleGate::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Infrastructure')]
final class SiteScopedRoutingTest extends TestCase
{
    public function testHandlersAndMiddlewareComeFromTheSiteContainer(): void
    {
        $tenant = new Container([Greeting::class => new Greeting('tenant')]);
        $site = new Container([Greeting::class => new Greeting('site')]);
        $handler = new SiteScopedHandler(GreetingHandler::class, $tenant);
        $middleware = new SiteScopedMiddleware(GreetingMiddleware::class, $tenant);
        $request = new ServerRequest('GET', '/');
        $siteRequest = $request->withAttribute(SiteContext::class, new SiteContext(SiteFixture::site(), $site));

        self::assertSame('site', (string) $handler->handle($siteRequest)->getBody());
        self::assertSame('tenant', (string) $handler($request)->getBody());

        $next = new CallbackHandler(static fn (ServerRequestInterface $r): ResponseInterface => new Response(200, [], is_string($r->getAttribute('greeting')) ? $r->getAttribute('greeting') : ''));
        self::assertSame('site', (string) $middleware->process($siteRequest, $next)->getBody());
        self::assertSame('tenant', (string) $middleware->process($request, $next)->getBody());
    }
}

final readonly class Greeting
{
    public function __construct(public string $from)
    {
    }
}

final readonly class GreetingHandler implements RequestHandlerInterface
{
    public function __construct(private Greeting $greeting)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], $this->greeting->from);
    }
}

final readonly class GreetingMiddleware implements MiddlewareInterface
{
    public function __construct(private Greeting $greeting)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('greeting', $this->greeting->from));
    }
}
