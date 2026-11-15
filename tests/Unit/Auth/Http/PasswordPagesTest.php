<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Http\ForgotPasswordHandler;
use SightPress\Core\Auth\Http\ResetPasswordHandler;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\User\UserStatus;

#[CoversClass(ForgotPasswordHandler::class)]
#[CoversClass(ResetPasswordHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class PasswordPagesTest extends TestCase
{
    private AuthFixture $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
    }

    public function testAsksForTheEmailAddress(): void
    {
        $response = $this->forgot($this->auth->request(uri: 'https://demo.example/password/forgot'));
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Reset your password - Demo</title>', $html);
        self::assertStringContainsString('<form id="forgot-password" action="/password/forgot" method="post" novalidate>', $html);
        self::assertStringContainsString('The address you sign in with.', $html);
    }

    public function testTheAnswerIsTheSameWhetherOrNotTheAddressHasAnAccount(): void
    {
        $this->auth->user();

        $known = (string) $this->forgot($this->postForgot('ada@example.org'))->getBody();
        $unknown = (string) $this->forgot($this->postForgot('grace@example.org'))->getBody();

        self::assertStringContainsString('<h1>Check your email</h1>', $known);
        self::assertStringContainsString('If ada@example.org has an account here', $known);
        self::assertSame(str_replace('ada@', 'grace@', $known), $unknown);
        self::assertCount(1, $this->auth->sent);
    }

    public function testAWrongAddressOrTooManyRequestsAreExplained(): void
    {
        $wrong = $this->forgot($this->postForgot('ada@'));
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('Enter an email address in the right format', (string) $wrong->getBody());

        for ($i = 0; $i < 3; ++$i) {
            $this->forgot($this->postForgot('ada@example.org'));
        }
        $throttled = $this->forgot($this->postForgot('ada@example.org'));
        self::assertSame(429, $throttled->getStatusCode());
        self::assertStringContainsString('You have asked for a link too many times.', (string) $throttled->getBody());
    }

    public function testALinkOpensTheFormForTheNewPassword(): void
    {
        $user = $this->auth->user();
        $path = '/password/reset/' . $this->auth->resetTokens()->create($user);

        $response = $this->reset($this->resetRequest('GET', $path));
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Choose a new password</h1>', $html);
        self::assertStringContainsString('For the account ada@example.org.', $html);
        self::assertStringContainsString('action="' . $path . '"', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringContainsString('>Change password</button>', $html);
    }

    public function testSetsTheNewPasswordAndSendsThePersonToSignIn(): void
    {
        $user = $this->auth->user();
        $path = '/password/reset/' . $this->auth->resetTokens()->create($user);
        $session = new Session();

        $response = $this->reset($this->resetRequest('POST', $path, ['password' => ' a new long password '], $session));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertTrue($this->auth->hasher->verify(' a new long password ', $user->passwordHash()));
        self::assertSame(['Your password has been changed. Sign in with your new password.'], Flash::take($session));
        self::assertSame(410, $this->reset($this->resetRequest('GET', $path))->getStatusCode(), 'a link works once');
    }

    public function testAPendingAccountChoosesItsFirstPassword(): void
    {
        $user = $this->auth->user(pending: true);
        $path = '/password/reset/' . $this->auth->resetTokens()->create($user);
        $session = new Session();

        self::assertStringContainsString('<h1>Choose your password</h1>', (string) $this->reset($this->resetRequest('GET', $path))->getBody());
        $this->reset($this->resetRequest('POST', $path, ['password' => 'my very first password'], $session));

        self::assertSame(UserStatus::Active, $user->status());
        self::assertSame(['Your password has been set. Sign in with it now.'], Flash::take($session));
    }

    public function testAWeakPasswordIsExplained(): void
    {
        $user = $this->auth->user();
        $path = '/password/reset/' . $this->auth->resetTokens()->create($user);

        $short = $this->reset($this->resetRequest('POST', $path, ['password' => 'short']));
        $empty = $this->reset($this->resetRequest('POST', $path, []));

        self::assertSame(422, $short->getStatusCode());
        self::assertStringContainsString('<title>Error: Choose a new password - Demo</title>', (string) $short->getBody());
        self::assertStringContainsString('Your password must be at least 12 characters long.', (string) $short->getBody());
        self::assertStringContainsString('Enter new password', (string) $empty->getBody());
        self::assertTrue($this->auth->hasher->verify(AuthFixture::PASSWORD, $user->passwordHash()));
    }

    public function testABrokenOrMissingLinkExplainsWhatToDo(): void
    {
        $response = $this->reset($this->resetRequest('GET', '/password/reset/nonsense'));
        $html = (string) $response->getBody();

        self::assertSame(410, $response->getStatusCode());
        self::assertStringContainsString('<h1>This link no longer works</h1>', $html);
        self::assertStringContainsString('<a href="/password/forgot">Ask for a new link</a>', $html);
        self::assertSame(410, $this->reset($this->auth->request(uri: 'https://demo.example/password/reset/'))->getStatusCode());
    }

    private function postForgot(string $email): ServerRequestInterface
    {
        return $this->auth->request('POST', 'https://demo.example/password/forgot', ['email' => $email]);
    }

    /**
     * @param array<string, string> $body
     */
    private function resetRequest(string $method, string $path, array $body = [], ?Session $session = null): ServerRequestInterface
    {
        return $this->auth->request($method, 'https://demo.example' . $path, $body, session: $session)
            ->withAttribute(ResetPasswordHandler::TOKEN_ATTRIBUTE, substr($path, strlen('/password/reset/')));
    }

    private function forgot(ServerRequestInterface $request): ResponseInterface
    {
        return new ForgotPasswordHandler($this->auth->pages(), $this->auth->validator(), $this->auth->passwordReset())->handle($request);
    }

    private function reset(ServerRequestInterface $request): ResponseInterface
    {
        return new ResetPasswordHandler(
            $this->auth->pages(),
            $this->auth->validator(),
            $this->auth->resetTokens(),
            $this->auth->passwordReset(),
        )->handle($request);
    }
}
