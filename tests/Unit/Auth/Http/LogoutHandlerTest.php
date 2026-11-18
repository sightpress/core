<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Http\LogoutHandler;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tests\Support\AuthFixture;

#[CoversClass(LogoutHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class LogoutHandlerTest extends TestCase
{
    private AuthFixture $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
    }

    public function testAsksToConfirmWithAButton(): void
    {
        $request = $this->auth->request(uri: 'https://demo.example/logout')->withAttribute(Actor::class, new Actor(1));

        $response = $this->handle($request);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Sign out</h1>', $html);
        self::assertStringContainsString('Do you want to sign out of Demo?', $html);
        self::assertStringContainsString('<form action="/logout" method="post">', $html);
        self::assertStringContainsString('<input type="hidden" name="csrf_name"', $html);
    }

    public function testVisitorsWhoAreNotSignedInGoToSignIn(): void
    {
        $response = $this->handle($this->auth->request(uri: 'https://demo.example/logout'));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testSignsOutAndForgetsTheDevice(): void
    {
        $user = $this->auth->user();
        $cookie = explode('=', explode(';', $this->auth->rememberTokens()->issue((int) $user->id(), true))[0], 2)[1];
        $session = new Session('id', ['auth.user_id' => $user->id(), 'other' => 1]);
        $request = $this->auth->request('POST', 'https://demo.example/logout', [], [RememberTokens::SECURE_COOKIE => $cookie], $session);

        $response = $this->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(['You have signed out.'], array_map(strval(...), Flash::take($session)));
        self::assertSame([], $session->all());
        self::assertNull($this->auth->rememberTokens()->consume($request));
    }

    private function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new LogoutHandler($this->auth->pages(), $this->auth->signIn(), $this->auth->rememberTokens())->handle($request);
    }
}
