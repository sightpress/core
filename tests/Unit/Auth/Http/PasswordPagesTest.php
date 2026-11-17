<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Http\ChoosePasswordHandler;
use SightPress\Core\Auth\Http\ForgotPasswordHandler;
use SightPress\Core\Auth\Http\ResetCodeHandler;
use SightPress\Core\Auth\Http\SetupAccountHandler;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\User\UserStatus;

#[CoversClass(ForgotPasswordHandler::class)]
#[CoversClass(ResetCodeHandler::class)]
#[CoversClass(SetupAccountHandler::class)]
#[CoversClass(ChoosePasswordHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class PasswordPagesTest extends TestCase
{
    private AuthFixture $auth;

    private Session $session;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->session = new Session();
    }

    public function testAsksForTheEmailAddress(): void
    {
        $response = $this->forgot('GET');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Reset your password - Demo</title>', $html);
        self::assertStringContainsString('<form id="forgot-password" action="/login/forgot" method="post" novalidate>', $html);
        self::assertStringContainsString('The address you sign in with.', $html);
        self::assertStringContainsString('>Send me a code</button>', $html);
    }

    public function testTheAnswerIsTheSameWhetherOrNotTheAddressHasAnAccount(): void
    {
        $this->auth->user();

        $known = $this->forgot('POST', ['email' => 'Ada@example.org']);
        $unknownSession = $this->session = new Session();
        $unknown = $this->forgot('POST', ['email' => 'grace@example.org']);

        foreach ([$known, $unknown] as $response) {
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/login/forgot/code', $response->getHeaderLine('Location'));
        }
        self::assertSame('grace@example.org', CodeSession::pending($unknownSession, VerificationPurpose::PasswordReset));
        self::assertCount(1, $this->auth->sent);
    }

    public function testAWrongAddressOrTooManyRequestsAreExplained(): void
    {
        $wrong = $this->forgot('POST', ['email' => 'ada@']);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('Enter an email address in the right format', (string) $wrong->getBody());

        self::assertSame(303, $this->forgot('POST', ['email' => 'ada@example.org'])->getStatusCode());
        self::assertSame(303, $this->forgot('POST', ['email' => 'ada@example.org'])->getStatusCode(), 'asking again within a minute goes on to the code');
        for ($i = 0; $i < 4; ++$i) {
            $this->auth->clock->advance(61);
            $this->forgot('POST', ['email' => 'ada@example.org']);
        }
        $this->auth->clock->advance(61);
        $throttled = $this->forgot('POST', ['email' => 'ada@example.org']);
        self::assertSame(429, $throttled->getStatusCode());
        self::assertStringContainsString('You have asked for a code too many times.', (string) $throttled->getBody());
    }

    public function testTheCodePageNeedsAnAddressFirst(): void
    {
        $response = $this->code('GET');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login/forgot', $response->getHeaderLine('Location'));
    }

    public function testTheCodePageHasOneAccessibleFieldAndSendsNothingByItself(): void
    {
        $this->auth->user();
        $this->forgot('POST', ['email' => 'ada@example.org']);

        $response = $this->code('GET');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Enter your code - Demo</title>', $html);
        self::assertSame(1, preg_match('#<input type="text" id="reset-code-code"([^>]*)>#', $html, $input));
        self::assertStringContainsString('autocomplete="one-time-code"', $input[1]);
        self::assertStringContainsString('inputmode="numeric"', $input[1]);
        self::assertStringContainsString('aria-describedby="reset-code-code-hint"', $input[1]);
        self::assertStringContainsString('<label for="reset-code-code">6-digit code</label>', $html);
        self::assertStringContainsString('We sent it by email to a•••@example.org.', $html);
        self::assertStringContainsString('The code works for 15 minutes, and only once.', $html);
        self::assertStringContainsString('<button type="submit">Continue</button>', $html);
        self::assertStringContainsString('name="resend" value="1"', $html);
        self::assertStringContainsString('You can ask for a new code once a minute.', $html);
    }

    public function testARightCodeLetsThePersonChooseANewPassword(): void
    {
        $user = $this->auth->user();
        $this->forgot('POST', ['email' => 'ada@example.org']);
        $code = AuthFixture::codeIn($this->auth->sent[0]);

        $wrong = $this->code('POST', ['code' => $code === '000000' ? '111 111' : '000 000']);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString(ResetCodeHandler::WRONG, (string) $wrong->getBody());
        self::assertStringContainsString('<title>Error: Enter your code - Demo</title>', (string) $wrong->getBody());
        self::assertStringContainsString('Enter the 6 digits of the code', (string) $this->code('POST', ['code' => '12'])->getBody());

        $right = $this->code('POST', ['code' => substr($code, 0, 3) . '-' . substr($code, 3)]);
        self::assertSame(303, $right->getStatusCode());
        self::assertSame('/login/password', $right->getHeaderLine('Location'));

        $page = (string) $this->choose('GET')->getBody();
        self::assertStringContainsString('<h1>Choose a new password</h1>', $page);
        self::assertStringContainsString('For the account ada@example.org.', $page);
        self::assertStringContainsString('action="/login/password"', $page);
        self::assertStringContainsString('autocomplete="new-password"', $page);
        self::assertStringContainsString('>Change password</button>', $page);

        $short = $this->choose('POST', ['password' => 'short']);
        self::assertSame(422, $short->getStatusCode());
        self::assertStringContainsString('Your password must be at least 12 characters long.', (string) $short->getBody());
        self::assertStringContainsString('Enter new password', (string) $this->choose('POST', [])->getBody());

        $done = $this->choose('POST', ['password' => ' a new long password ']);
        self::assertSame('/login', $done->getHeaderLine('Location'));
        self::assertTrue($this->auth->hasher->verify(' a new long password ', $user->passwordHash()));
        self::assertSame(['Your password has been changed. Sign in with your new password.'], Flash::take($this->session));
        self::assertSame('/login/forgot', $this->choose('GET')->getHeaderLine('Location'), 'only once');
    }

    public function testSendingANewCodeWaitsAMinuteAndSaysSo(): void
    {
        $this->auth->user();
        $this->forgot('POST', ['email' => 'ada@example.org']);

        $soon = $this->code('POST', ['resend' => '1']);
        self::assertSame(429, $soon->getStatusCode());
        self::assertStringContainsString('Wait 60 seconds, then send a new code. The code we sent before still works.', (string) $soon->getBody());
        self::assertCount(1, $this->auth->sent);

        $this->auth->clock->advance(60);
        $sent = $this->code('POST', ['resend' => '1']);
        self::assertSame('/login/forgot/code', $sent->getHeaderLine('Location'));
        self::assertCount(2, $this->auth->sent);
        self::assertStringContainsString('We have sent a new code to a•••@example.org.', (string) $this->code('GET')->getBody());

        for ($i = 0; $i < 3; ++$i) {
            $this->auth->clock->advance(61);
            $this->code('POST', ['resend' => '1']);
        }
        $this->auth->clock->advance(61);
        $limited = $this->code('POST', ['resend' => '1']);
        self::assertStringContainsString('You have asked for a code too many times.', (string) $limited->getBody());
    }

    public function testTooManyTriesAreExplained(): void
    {
        $this->forgot('POST', ['email' => 'ada@example.org']);
        for ($i = 0; $i < 10; ++$i) {
            $this->code('POST', ['code' => '123456']);
        }

        $limited = $this->code('POST', ['code' => '123456']);

        self::assertSame(429, $limited->getStatusCode());
        self::assertStringContainsString('You have tried a code too many times.', (string) $limited->getBody());
    }

    public function testTheFirstAdminSetsUpTheirAccountWithTheSetupCode(): void
    {
        $user = $this->auth->user(pending: true);
        $code = $this->auth->codes()->issue(VerificationPurpose::AccountSetup, VerificationChannel::Email, 'ada@example.org', (int) $user->id());

        $page = $this->setupPage('GET');
        $html = (string) $page->getBody();
        self::assertStringContainsString('<title>Set up your account - Demo</title>', $html);
        self::assertStringContainsString('action="/login/setup"', $html);
        self::assertStringContainsString('The setup code you were given.', $html);
        self::assertStringNotContainsString('name="resend"', $html);

        self::assertStringContainsString('Enter email address', (string) $this->setupPage('POST', ['code' => '123456'])->getBody());
        $wrong = $this->setupPage('POST', ['email' => 'max@example.org', 'code' => $code->code]);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('That email address and code don’t match', (string) $wrong->getBody());

        $right = $this->setupPage('POST', ['email' => 'ada@example.org', 'code' => $code->readable()]);
        self::assertSame('/login/password', $right->getHeaderLine('Location'));
        self::assertStringContainsString('<h1>Choose your password</h1>', (string) $this->choose('GET')->getBody());
        $this->choose('POST', ['password' => 'my very first password']);
        self::assertSame(UserStatus::Active, $user->status());
        self::assertSame(['Your password has been set. Sign in with it now.'], Flash::take($this->session));
    }

    public function testTooManySetupTriesAreExplained(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->setupPage('POST', ['email' => 'ada@example.org', 'code' => '123456']);
        }

        self::assertSame(429, $this->setupPage('POST', ['email' => 'ada@example.org', 'code' => '123456'])->getStatusCode());
    }

    public function testChoosingAPasswordWithoutAProvedCodeSendsThePersonBack(): void
    {
        $user = $this->auth->user();

        $none = $this->choose('GET');
        self::assertSame('/login/forgot', $none->getHeaderLine('Location'));
        self::assertSame(['Your code has run out of time. Ask for a new code to choose your password.'], Flash::take($this->session));

        CodeSession::verified($this->session, VerificationPurpose::PasswordReset, (int) $user->id(), $this->auth->clock->now()->getTimestamp());
        $this->auth->clock->advance(CodeSession::VERIFIED_FOR + 1);
        self::assertSame('/login/forgot', $this->choose('POST', ['password' => 'a new long password'])->getHeaderLine('Location'));

        CodeSession::verified($this->session, VerificationPurpose::PasswordReset, 999, $this->auth->clock->now()->getTimestamp());
        self::assertSame('/login/forgot', $this->choose('GET')->getHeaderLine('Location'), 'the account is gone');
        self::assertTrue($this->auth->hasher->verify(AuthFixture::PASSWORD, $user->passwordHash()));
    }

    /**
     * @param array<string, string> $body
     */
    private function forgot(string $method, array $body = []): ResponseInterface
    {
        return new ForgotPasswordHandler($this->auth->pages(), $this->auth->validator(), $this->auth->passwordReset(), $this->auth->timing())
            ->handle($this->auth->request($method, 'https://demo.example/login/forgot', $body, session: $this->session));
    }

    /**
     * @param array<string, string> $body
     */
    private function code(string $method, array $body = []): ResponseInterface
    {
        return new ResetCodeHandler($this->auth->pages(), $this->auth->validator(), $this->auth->passwordReset(), $this->auth->timing(), $this->auth->clock)
            ->handle($this->auth->request($method, 'https://demo.example/login/forgot/code', $body, session: $this->session));
    }

    /**
     * @param array<string, string> $body
     */
    private function setupPage(string $method, array $body = []): ResponseInterface
    {
        return new SetupAccountHandler($this->auth->pages(), $this->auth->validator(), $this->auth->passwordReset(), $this->auth->clock)
            ->handle($this->auth->request($method, 'https://demo.example/login/setup', $body, session: $this->session));
    }

    /**
     * @param array<string, string> $body
     */
    private function choose(string $method, array $body = []): ResponseInterface
    {
        return new ChoosePasswordHandler($this->auth->pages(), $this->auth->validator(), $this->auth->passwordReset(), $this->auth->users, $this->auth->clock)
            ->handle($this->auth->request($method, 'https://demo.example/login/password', $body, session: $this->session));
    }
}
