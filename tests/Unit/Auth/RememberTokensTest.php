<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Tests\Support\AuthFixture;

#[CoversClass(RememberTokens::class)]
#[UsesNamespace('SightPress\Core')]
final class RememberTokensTest extends TestCase
{
    private AuthFixture $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
    }

    public function testATokenSignsInOnceAndOnlyItsHashIsStored(): void
    {
        $tokens = $this->auth->rememberTokens();
        $header = $tokens->issue(7, true);

        self::assertMatchesRegularExpression(
            '/^__Host-sightpress_remember=[a-f0-9]{32}\.[A-Za-z0-9_-]{43}; Path=\/; Max-Age=2592000; HttpOnly; SameSite=Lax; Secure$/',
            $header,
        );
        $value = $this->cookieValue($header);
        $stored = $this->auth->database->connection->createQueryBuilder()
            ->select('validator_hash')->from(RememberTokens::TABLE)->fetchOne();
        self::assertSame(hash('sha256', explode('.', $value)[1]), $stored);

        $request = $this->withCookie(RememberTokens::SECURE_COOKIE, $value);
        self::assertTrue(RememberTokens::hasCookie($request));
        self::assertSame(7, $tokens->consume($request));
        self::assertNull($tokens->consume($request), 'a token works once');
    }

    public function testOverPlainHttpTheCookieHasNoPrefix(): void
    {
        $header = $this->auth->rememberTokens()->issue(7, false);

        self::assertStringStartsWith('sightpress_remember=', $header);
        self::assertStringNotContainsString('Secure', $header);
        self::assertSame(7, $this->auth->rememberTokens()->consume($this->withCookie(RememberTokens::COOKIE, $this->cookieValue($header), 'http')));
    }

    public function testExpiredForgedOrMalformedTokensSignNobodyIn(): void
    {
        $tokens = $this->auth->rememberTokens();
        $expired = $this->cookieValue($tokens->issue(7, true));
        $this->auth->clock->advance(RememberTokens::LIFETIME + 1);
        self::assertNull($tokens->consume($this->withCookie(RememberTokens::SECURE_COOKIE, $expired)));

        $forged = explode('.', $this->cookieValue($tokens->issue(7, true)))[0] . '.' . str_repeat('A', 43);
        self::assertNull($tokens->consume($this->withCookie(RememberTokens::SECURE_COOKIE, $forged)));
        self::assertNull($tokens->consume($this->withCookie(RememberTokens::SECURE_COOKIE, $forged)), 'a wrong guess deletes the token');

        self::assertNull($tokens->consume($this->withCookie(RememberTokens::SECURE_COOKIE, str_repeat('a', 32) . '.' . str_repeat('A', 43))));
        self::assertNull($tokens->consume($this->withCookie(RememberTokens::SECURE_COOKIE, 'nonsense')));
        self::assertFalse(RememberTokens::hasCookie($this->withCookie(RememberTokens::SECURE_COOKIE, 'nonsense')));
        self::assertNull($tokens->consume($this->auth->request()));
    }

    public function testARowWithoutAUserIdSignsNobodyIn(): void
    {
        $selector = str_repeat('b', 32);
        $validator = str_repeat('C', 43);
        $this->auth->database->connection->insert(RememberTokens::TABLE, [
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'user_id' => 'nobody',
            'expires_at' => '2099-01-01 00:00:00',
        ]);

        self::assertNull($this->auth->rememberTokens()->consume($this->withCookie(RememberTokens::SECURE_COOKIE, $selector . '.' . $validator)));
    }

    public function testForgettingDeletesTheTokenAndTheCookie(): void
    {
        $tokens = $this->auth->rememberTokens();
        $value = $this->cookieValue($tokens->issue(7, true));
        $request = $this->withCookie(RememberTokens::SECURE_COOKIE, $value);

        self::assertSame(
            '__Host-sightpress_remember=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax; Secure',
            $tokens->forget($request),
        );
        self::assertNull($tokens->consume($request));
        self::assertStringStartsWith('sightpress_remember=;', $tokens->forget($this->auth->request(uri: 'http://demo.example/logout')));
    }

    public function testAPasswordChangeForgetsEveryDeviceOfTheUser(): void
    {
        $tokens = $this->auth->rememberTokens();
        $first = $this->cookieValue($tokens->issue(7, true));
        $second = $this->cookieValue($tokens->issue(7, true));
        $other = $this->cookieValue($tokens->issue(8, true));

        $tokens->forgetUser(7);

        self::assertNull($tokens->consume($this->withCookie(RememberTokens::SECURE_COOKIE, $first)));
        self::assertNull($tokens->consume($this->withCookie(RememberTokens::SECURE_COOKIE, $second)));
        self::assertSame(8, $tokens->consume($this->withCookie(RememberTokens::SECURE_COOKIE, $other)));
    }

    private function cookieValue(string $header): string
    {
        return explode('=', explode(';', $header)[0], 2)[1];
    }

    private function withCookie(string $name, string $value, string $scheme = 'https'): ServerRequestInterface
    {
        return $this->auth->request(uri: $scheme . '://demo.example/', cookies: [$name => $value]);
    }
}
