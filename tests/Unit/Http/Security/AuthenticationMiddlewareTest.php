<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Security;

use DI\Container;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Security\AuthenticationMiddleware;
use SightPress\Core\Http\Security\Authenticator;
use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(AuthenticationMiddleware::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Infrastructure')]
final class AuthenticationMiddlewareTest extends TestCase
{
    public function testTheSitesAuthenticatorSeesTheRequestAndTheResponse(): void
    {
        $container = new Container([Authenticator::class => new class () implements Authenticator {
            public function authenticate(ServerRequestInterface $request): ServerRequestInterface
            {
                return $request->withAttribute('who', 'ada');
            }

            public function respond(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                $who = $request->getAttribute('who');

                return $response->withHeader('X-Who', is_string($who) ? $who : '');
            }
        }]);
        $request = new ServerRequest('GET', '/')->withAttribute(SiteContext::class, new SiteContext(SiteFixture::site(), $container));

        $response = new AuthenticationMiddleware()->process($request, new CallbackHandler(
            static fn (ServerRequestInterface $r): ResponseInterface => new Response(200, ['X-Seen' => is_string($r->getAttribute('who')) ? $r->getAttribute('who') : '']),
        ));

        self::assertSame('ada', $response->getHeaderLine('X-Seen'));
        self::assertSame('ada', $response->getHeaderLine('X-Who'));
    }

    public function testRequestsWithoutASiteOrAnAuthenticatorStayAnonymous(): void
    {
        $handler = new CallbackHandler(static fn (ServerRequestInterface $r): ResponseInterface => new Response(204));
        $withoutAuthenticator = new ServerRequest('GET', '/')
            ->withAttribute(SiteContext::class, new SiteContext(SiteFixture::site(), new Container()));

        self::assertSame(204, new AuthenticationMiddleware()->process(new ServerRequest('GET', '/'), $handler)->getStatusCode());
        self::assertSame(204, new AuthenticationMiddleware()->process($withoutAuthenticator, $handler)->getStatusCode());
    }
}
