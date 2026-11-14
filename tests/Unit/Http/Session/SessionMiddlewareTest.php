<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Session;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Http\Session\SessionMiddleware;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Tests\Support\CallbackHandler;

#[CoversClass(SessionMiddleware::class)]
#[UsesClass(Session::class)]
final class SessionMiddlewareTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $stored = [];

    public function testAVisitorWithoutDataGetsNoCookie(): void
    {
        $response = $this->send(new ServerRequest('GET', 'http://example.org/'), static fn (Session $s) => null);

        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('Cache-Control'));
        self::assertSame([], $this->stored);
    }

    public function testStoringDataStartsTheSessionWithASafeCookie(): void
    {
        $response = $this->send(new ServerRequest('GET', 'http://example.org/'), static fn (Session $s) => $s->set('user', 7));

        $id = (string) array_key_first($this->stored);
        self::assertSame(['user' => 7], $this->stored[$id]);
        self::assertSame("sightpress_session={$id}; Path=/; HttpOnly; SameSite=Lax", $response->getHeaderLine('Set-Cookie'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testOverHttpsTheCookieIsSecureAndBoundToTheHost(): void
    {
        $response = $this->send(new ServerRequest('GET', 'https://example.org/'), static fn (Session $s) => $s->set('user', 7));

        self::assertMatchesRegularExpression(
            '/^__Host-sightpress_session=[A-Za-z0-9_-]{43}; Path=\/; HttpOnly; SameSite=Lax; Secure$/',
            $response->getHeaderLine('Set-Cookie'),
        );
    }

    public function testAKnownSessionIsLoadedAndSavedWithoutANewCookie(): void
    {
        $id = str_repeat('a', 43);
        $this->stored[$id] = ['user' => 7];
        $seen = null;

        $response = $this->send(
            $this->withCookie(new ServerRequest('GET', 'http://example.org/'), $id),
            static function (Session $s) use (&$seen): void {
                $seen = $s->get('user');
                $s->set('visits', 2);
            },
        );

        self::assertSame(7, $seen);
        self::assertSame(['user' => 7, 'visits' => 2], $this->stored[$id]);
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testSigningInGivesANewIdAndDeletesTheOldOne(): void
    {
        $old = str_repeat('a', 43);
        $this->stored[$old] = ['csrf' => 'x'];

        $response = $this->send(
            $this->withCookie(new ServerRequest('POST', 'http://example.org/sign-in'), $old),
            static function (Session $s): void {
                $s->regenerate();
                $s->set('user', 7);
            },
        );

        self::assertArrayNotHasKey($old, $this->stored);
        $new = (string) array_key_first($this->stored);
        self::assertSame(['csrf' => 'x', 'user' => 7], $this->stored[$new]);
        self::assertStringStartsWith("sightpress_session={$new};", $response->getHeaderLine('Set-Cookie'));
    }

    public function testSigningOutDeletesTheSessionAndTheCookie(): void
    {
        $id = str_repeat('b', 43);
        $this->stored[$id] = ['user' => 7];

        $response = $this->send(
            $this->withCookie(new ServerRequest('POST', 'https://example.org/sign-out'), $id, true),
            static fn (Session $s) => $s->invalidate(),
        );

        self::assertSame([], $this->stored);
        self::assertSame(
            '__Host-sightpress_session=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax; Secure',
            $response->getHeaderLine('Set-Cookie'),
        );
    }

    public function testAnEmptiedSessionIsDeleted(): void
    {
        $id = str_repeat('c', 43);
        $this->stored[$id] = ['flash' => 'saved'];

        $response = $this->send(
            $this->withCookie(new ServerRequest('GET', 'http://example.org/'), $id),
            static fn (Session $s) => $s->remove('flash'),
        );

        self::assertSame([], $this->stored);
        self::assertStringStartsWith('sightpress_session=; Path=/; Max-Age=0;', $response->getHeaderLine('Set-Cookie'));
    }

    public function testUnknownOrMalformedIdsStartAFreshSession(): void
    {
        foreach ([str_repeat('d', 43), 'short', '../../etc/passwd' . str_repeat('x', 27)] as $cookie) {
            $seen = 'unset';
            $this->send(
                $this->withCookie(new ServerRequest('GET', 'http://example.org/'), $cookie),
                static function (Session $s) use (&$seen): void {
                    $seen = $s->id();
                },
            );

            self::assertNull($seen, $cookie);
        }
    }

    private function withCookie(ServerRequestInterface $request, string $id, bool $secure = false): ServerRequestInterface
    {
        return $request->withCookieParams([$secure ? SessionMiddleware::SECURE_COOKIE : SessionMiddleware::COOKIE => $id]);
    }

    /**
     * @param \Closure(Session): mixed $use
     */
    private function send(ServerRequestInterface $request, \Closure $use): ResponseInterface
    {
        $stored = &$this->stored;
        $store = new class ($stored) implements SessionStore {
            /**
             * @param array<string, array<string, mixed>> $stored
             */
            public function __construct(private array &$stored)
            {
            }

            public function read(string $id): ?array
            {
                return $this->stored[$id] ?? null;
            }

            public function write(string $id, array $data): void
            {
                $this->stored[$id] = $data;
            }

            public function delete(string $id): void
            {
                unset($this->stored[$id]);
            }
        };

        return new SessionMiddleware($store)->process($request, new CallbackHandler(
            static function (ServerRequestInterface $request) use ($use): ResponseInterface {
                $use(Session::fromRequest($request));

                return new Response();
            },
        ));
    }
}
