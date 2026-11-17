<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Http\AuthPages;
use SightPress\Core\Auth\Http\LoginHandler;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tests\Support\AuthFixture;

#[CoversClass(LoginHandler::class)]
#[CoversClass(AuthPages::class)]
#[UsesNamespace('SightPress\Core')]
final class LoginHandlerTest extends TestCase
{
    private AuthFixture $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
    }

    public function testShowsAnAccessibleSignInForm(): void
    {
        $session = new Session();
        Flash::add($session, 'You have signed out.');

        $response = $this->handle($this->auth->request(session: $session));
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<title>Sign in - Demo</title>', $html);
        self::assertStringContainsString('<h1>Sign in</h1>', $html);
        self::assertStringContainsString('<form id="sign-in" action="/login" method="post" novalidate>', $html);
        self::assertStringContainsString('autocomplete="username"', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
        self::assertStringContainsString('<input type="checkbox" id="sign-in-remember" value="1" name="remember"', $html, 'unticked');
        self::assertStringContainsString('Keep me signed in on this device for 30 days (optional)', $html);
        self::assertStringContainsString('<a href="/login/forgot">Forgot your password?</a>', $html);
        self::assertStringContainsString('<div class="messages" role="status">    <p>You have signed out.</p></div>', $html);
        self::assertSame([], Flash::take($session), 'a flash message is shown once');
    }

    public function testKeepsWhereThePersonCameFromInTheFormAddress(): void
    {
        $html = (string) $this->handle($this->auth->request(uri: 'https://demo.example/login?return=/admin/pages%3Fsort%3Dtitle'))->getBody();
        self::assertStringContainsString('action="/login?return=%2Fadmin%2Fpages%3Fsort%3Dtitle"', $html);

        $html = (string) $this->handle($this->auth->request(uri: 'https://demo.example/login?return=//evil.example'))->getBody();
        self::assertStringContainsString('action="/login"', $html);

        $referred = $this->auth->request()->withHeader('Referer', 'https://demo.example/about?tab=team');
        self::assertStringContainsString('action="/login?return=%2Fabout%3Ftab%3Dteam"', (string) $this->handle($referred)->getBody());

        foreach (['https://evil.example/about', 'https://demo.example', 'not a url at all ::'] as $referrer) {
            $html = (string) $this->handle($this->auth->request()->withHeader('Referer', $referrer))->getBody();
            self::assertStringContainsString('action="/login"', $html, $referrer);
        }
    }

    public function testSignsInAndGoesBackWhereThePersonCameFrom(): void
    {
        $user = $this->auth->user();
        $session = new Session();

        $response = $this->post(['email' => 'ada@example.org', 'password' => AuthFixture::PASSWORD], '?return=/admin/pages', $session);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/pages', $response->getHeaderLine('Location'));
        self::assertSame($user->id(), $session->get(SignIn::SESSION_USER));
        self::assertFalse($response->hasHeader('Set-Cookie'), 'keep me signed in is off unless ticked');
    }

    public function testMembersNeverGoToTheAdminPanel(): void
    {
        $this->auth->user(role: 'member');

        $response = $this->post(['email' => 'ada@example.org', 'password' => AuthFixture::PASSWORD], '?return=/admin');

        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testTickingKeepMeSignedInSetsTheLongLivedCookie(): void
    {
        $this->auth->user();

        $response = $this->post(['email' => 'ada@example.org', 'password' => AuthFixture::PASSWORD, 'remember' => '1']);

        self::assertSame('/admin', $response->getHeaderLine('Location'));
        self::assertStringStartsWith(RememberTokens::SECURE_COOKIE . '=', $response->getHeaderLine('Set-Cookie'));
    }

    public function testMissingFieldsAndWrongDetailsAreExplained(): void
    {
        $this->auth->user();

        $empty = $this->post([]);
        $html = (string) $empty->getBody();
        self::assertSame(422, $empty->getStatusCode());
        self::assertStringContainsString('<title>Error: Sign in - Demo</title>', $html);
        self::assertStringContainsString('<a href="#sign-in-email">Enter email address</a>', $html);
        self::assertStringContainsString('<a href="#sign-in-password">Enter password</a>', $html);

        $wrong = $this->post(['email' => 'ada@example.org', 'password' => 'not the password', 'remember' => '1']);
        $html = (string) $wrong->getBody();
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('<a href="#sign-in-email">' . LoginHandler::WRONG . '</a>', $html);
        self::assertStringContainsString('value="ada@example.org"', $html, 'the address is kept');
        self::assertStringNotContainsString('not the password', $html, 'the password is never sent back');
    }

    public function testTooManyAttemptsSayHowLongToWait(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->post(['email' => 'ada@example.org', 'password' => 'not the password']);
        }

        $response = $this->post(['email' => 'ada@example.org', 'password' => 'not the password']);

        self::assertSame(429, $response->getStatusCode());
        self::assertMatchesRegularExpression('/You have tried to sign in too many times\. Wait 1[4-6] minutes and try again\./', (string) $response->getBody());
    }

    public function testSomeoneAlreadySignedInIsSentOn(): void
    {
        $request = $this->auth->request(uri: 'https://demo.example/login?return=/about')
            ->withAttribute(Actor::class, new Actor(1, ['admin.access']));

        $response = $this->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/about', $response->getHeaderLine('Location'));
    }

    public function testTheWaitIsInWholeMinutes(): void
    {
        self::assertSame('You have tried too many times. Wait 1 minute and try again.', AuthPages::tooManyAttempts('tried', 60));
        self::assertSame('You have tried too many times. Wait 2 minutes and try again.', AuthPages::tooManyAttempts('tried', 61));
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body, string $query = '', ?Session $session = null): ResponseInterface
    {
        return $this->handle($this->auth->request('POST', 'https://demo.example/login' . $query, $body, session: $session));
    }

    private function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new LoginHandler(
            $this->auth->pages(),
            $this->auth->validator(),
            $this->auth->signIn(),
            $this->auth->database->roles(),
            $this->auth->rememberTokens(),
        )->handle($request);
    }
}
