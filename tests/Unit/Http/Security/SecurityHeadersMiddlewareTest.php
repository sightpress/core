<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Security;

use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Security\CspNonce;
use SightPress\Core\Http\Security\SecurityHeadersMiddleware;
use SightPress\Core\Tests\Support\CallbackHandler;

#[CoversClass(SecurityHeadersMiddleware::class)]
#[CoversClass(CspNonce::class)]
final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testEveryResponseGetsTheSecurityHeaders(): void
    {
        $nonce = null;
        $response = new SecurityHeadersMiddleware()->process(
            new ServerRequest('GET', 'http://example.org/'),
            new CallbackHandler(static function (ServerRequestInterface $request) use (&$nonce): Response {
                $nonce = CspNonce::fromRequest($request)->value;

                return new Response();
            }),
        );

        self::assertIsString($nonce);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/]{24}$/', $nonce);
        self::assertSame(
            "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; "
            . "img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; object-src 'none'; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            $response->getHeaderLine('Content-Security-Policy'),
        );
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame(SecurityHeadersMiddleware::PERMISSIONS_POLICY, $response->getHeaderLine('Permissions-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertFalse($response->hasHeader('Strict-Transport-Security'), 'no HSTS over plain HTTP');
    }

    public function testOverHttpsBrowsersMustKeepUsingHttps(): void
    {
        $response = new SecurityHeadersMiddleware()->process(
            new ServerRequest('GET', 'https://example.org/'),
            new CallbackHandler(static fn () => new Response()),
        );

        self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
        self::assertStringEndsWith('; upgrade-insecure-requests', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testEachRequestGetsANewNonce(): void
    {
        $nonces = [];
        $handler = new CallbackHandler(static function (ServerRequestInterface $request) use (&$nonces): Response {
            $nonces[] = CspNonce::fromRequest($request)->value;

            return new Response();
        });

        new SecurityHeadersMiddleware()->process(new ServerRequest('GET', '/'), $handler);
        new SecurityHeadersMiddleware()->process(new ServerRequest('GET', '/'), $handler);

        self::assertCount(2, array_unique($nonces));
    }

    public function testHeadersTheHandlerSetAreKept(): void
    {
        $response = new SecurityHeadersMiddleware()->process(
            new ServerRequest('GET', '/'),
            new CallbackHandler(static fn () => new Response(404, [
                'Content-Security-Policy' => "default-src 'none'",
                'Referrer-Policy' => 'no-referrer',
            ])),
        );

        self::assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testTheNonceComesFromTheRequest(): void
    {
        $this->expectException(LogicException::class);

        CspNonce::fromRequest(new ServerRequest('GET', '/'));
    }
}
