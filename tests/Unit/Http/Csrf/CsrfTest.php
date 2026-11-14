<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Csrf;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Csrf\CsrfMiddleware;
use SightPress\Core\Http\Csrf\CsrfToken;
use SightPress\Core\Http\Csrf\CsrfTokenMismatchException;
use SightPress\Core\Http\Csrf\CsrfTokens;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Tests\Support\CallbackHandler;

#[CoversClass(CsrfMiddleware::class)]
#[CoversClass(CsrfTokens::class)]
#[CoversClass(CsrfToken::class)]
#[UsesClass(Session::class)]
final class CsrfTest extends TestCase
{
    public function testReadingAPageStartsNoSession(): void
    {
        $session = new Session();

        $this->send(new ServerRequest('GET', '/'), $session);

        self::assertSame([], $session->all());
    }

    public function testAFormTokenIsStoredInTheSessionAndReusedOnThePage(): void
    {
        $session = new Session();
        $token = null;

        $this->send(new ServerRequest('GET', '/form'), $session, static function (CsrfTokens $tokens) use (&$token): void {
            $token = $tokens->token();
            self::assertSame($token, $tokens->token());
        });

        self::assertInstanceOf(CsrfToken::class, $token);
        self::assertSame(['csrf_name', 'csrf_value'], [$token->nameKey, $token->valueKey]);
        self::assertStringStartsWith('csrf', $token->name);
        self::assertArrayHasKey($token->name, (array) $session->get(CsrfTokens::SESSION_KEY));
    }

    public function testAFormWithAValidTokenIsAcceptedAndTheTokenStaysValid(): void
    {
        $session = new Session();
        $token = $this->tokenFor($session);
        $handled = 0;

        foreach ([1, 2] as $attempt) {
            $this->send($this->post($token), $session, static function () use (&$handled): void {
                ++$handled;
            });
        }

        self::assertSame(2, $handled);
    }

    public function testAFormWithoutAValidTokenIsRefused(): void
    {
        $session = new Session();
        $token = $this->tokenFor($session);
        $forged = new CsrfToken($token->nameKey, $token->name, $token->valueKey, base64_encode(str_repeat('x', 64)));

        foreach ([new ServerRequest('DELETE', '/pages/1'), $this->post($forged)] as $request) {
            try {
                $this->send($request, $session, static fn () => self::fail('The handler ran.'));
                self::fail('The request was accepted.');
            } catch (CsrfTokenMismatchException $exception) {
                self::assertSame(403, $exception->getCode());
            }
        }
    }

    public function testTokensComeFromTheRequest(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('There are no CSRF tokens: the CSRF middleware must run before this code.');

        CsrfTokens::fromRequest(new ServerRequest('GET', '/'));
    }

    private function tokenFor(Session $session): CsrfToken
    {
        $token = null;
        $this->send(new ServerRequest('GET', '/form'), $session, static function (CsrfTokens $tokens) use (&$token): void {
            $token = $tokens->token();
        });
        self::assertInstanceOf(CsrfToken::class, $token);

        return $token;
    }

    private function post(CsrfToken $token): ServerRequestInterface
    {
        return new ServerRequest('POST', '/pages')->withParsedBody([
            $token->nameKey => $token->name,
            $token->valueKey => $token->value,
            'title' => 'Hello',
        ]);
    }

    /**
     * @param (\Closure(CsrfTokens): mixed)|null $use
     */
    private function send(ServerRequestInterface $request, Session $session, ?\Closure $use = null): ResponseInterface
    {
        return new CsrfMiddleware(new Psr17Factory())->process(
            $request->withAttribute(Session::class, $session),
            new CallbackHandler(static function (ServerRequestInterface $request) use ($use): ResponseInterface {
                if ($use !== null) {
                    $use(CsrfTokens::fromRequest($request));
                }

                return new Response();
            }),
        );
    }
}
