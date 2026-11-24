<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Account\EmailChange;
use SightPress\Core\Auth\AnyEmailPolicy;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\EmailPolicy;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Http\AuthPages;
use SightPress\Core\Auth\Http\ChangeEmailCodeHandler;
use SightPress\Core\Auth\Http\ChangeEmailHandler;
use SightPress\Core\Auth\Http\ChangePasswordHandler;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\Text;
use SightPress\Module\Event\AccountChanged;
use SightPress\Core\User\Entity\User;
use Symfony\Component\Mime\Email;

/**
 * *My account* → "Change your password" and "Change your email address" (U-3).
 */
#[CoversClass(ChangePasswordHandler::class)]
#[CoversClass(ChangeEmailHandler::class)]
#[CoversClass(ChangeEmailCodeHandler::class)]
#[CoversClass(EmailChange::class)]
#[CoversClass(AuthPages::class)]
#[CoversClass(SignIn::class)]
#[UsesNamespace('SightPress\Core')]
final class AccountSignInDetailsTest extends TestCase
{
    private AuthFixture $auth;

    private Session $session;

    private RecordingEvents $events;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->session = new Session();
        $this->events = new RecordingEvents();
    }

    public function testVisitorsAreSentToSignInAndBack(): void
    {
        foreach ([[$this->passwordHandler(), '/account/password'], [$this->emailHandler(), '/account/email'], [$this->codeHandler(), '/account/email']] as [$handler, $return]) {
            $response = $handler->handle($this->request(null, 'GET', '/account/password'));
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/login?' . http_build_query(['return' => $return]), $response->getHeaderLine('Location'));
        }
    }

    public function testThePasswordPageAsksForTheCurrentAndANewPassword(): void
    {
        $ada = $this->auth->user();

        $response = $this->passwordHandler()->handle($this->request($ada, 'GET', '/account/password'));
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('<title>Change your password - Demo</title>', $html);
        self::assertStringContainsString('<h1>Change your password</h1>', $html);
        self::assertStringContainsString('For the account ada@example.org.', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringContainsString('At least 12 characters', $html);
        self::assertStringContainsString('<a href="/account">Back to My account</a>', $html);
    }

    public function testAWrongCurrentPasswordIsExplainedAndNothingChanges(): void
    {
        $ada = $this->auth->user();
        $hash = $ada->passwordHash();

        $response = $this->changePassword($ada, 'Not my password 1!', 'A brand new password 9?');
        $html = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<title>Error: Change your password - Demo</title>', $html);
        self::assertStringContainsString('<a href="#change-password-current-password">Your current password is not right. Check it and try again.</a>', $html);
        self::assertSame($hash, $ada->passwordHash());
        self::assertSame([], $this->events->events);
    }

    public function testTheNewPasswordMustMeetThePolicyAndDifferFromTheCurrentOne(): void
    {
        $ada = $this->auth->user();

        $short = (string) $this->changePassword($ada, AuthFixture::PASSWORD, 'short')->getBody();
        $same = (string) $this->changePassword($ada, AuthFixture::PASSWORD, AuthFixture::PASSWORD)->getBody();
        $empty = (string) $this->changePassword($ada, '', '')->getBody();

        self::assertStringContainsString('href="#change-password-new-password"', $short);
        self::assertStringContainsString('Choose a new password that is different from your current one.', $same);
        self::assertStringContainsString('Enter current password', $empty);
        self::assertSame([], $this->events->events);
    }

    public function testTooManyWrongPasswordsAreStoppedLikeSigningIn(): void
    {
        $ada = $this->auth->user();
        for ($i = 0; $i < 5; ++$i) {
            $this->changePassword($ada, 'Not my password 1!', 'A brand new password 9?');
        }

        $html = (string) $this->changePassword($ada, AuthFixture::PASSWORD, 'A brand new password 9?')->getBody();

        self::assertMatchesRegularExpression('#You have typed a wrong password too many times. Wait 1[56] minutes and try again.#', $html);
        self::assertSame([], $this->events->events);
    }

    public function testANewPasswordKeepsThisSessionAndSignsOutEveryOtherOne(): void
    {
        $ada = $this->auth->user();
        $signIn = $this->auth->signIn();
        $other = $this->auth->request(session: new Session());
        $signIn->start($other, $ada);
        $tokens = $this->auth->rememberTokens();
        $cookie = $tokens->issue((int) $ada->id(), true);
        $here = $this->request($ada, 'POST', '/account/password', ['current_password' => AuthFixture::PASSWORD, 'new_password' => 'A brand new password 9?']);
        $signIn->start($here, $ada);
        $here = $here->withCookieParams([explode('=', explode(';', $cookie)[0], 2)[0] => explode('=', explode(';', $cookie)[0], 2)[1]]);

        $response = $this->passwordHandler()->handle($here);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/account', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'), 'this browser\'s "keep me signed in" cookie is removed');
        self::assertTrue($this->auth->hasher->verify('A brand new password 9?', $ada->passwordHash()));
        self::assertTrue($signIn->isCurrent($here, $ada), 'this session goes on');
        self::assertFalse($signIn->isCurrent($other, $ada), 'every other session is signed out');
        self::assertSame([], $this->auth->database->connection->createQueryBuilder()->select('selector')->from('auth_remember_tokens')->executeQuery()->fetchFirstColumn());
        self::assertEquals([new AccountChanged((int) $ada->id(), $ada->id(), ['password'])], $this->events->events);
        self::assertSame(['Your password has been changed. You are still signed in here, and signed out everywhere else.'], Text::of(array_map(strval(...), Flash::take($this->session))));

        $quiet = $this->passwordHandler()->handle($this->request($ada, 'POST', '/account/password', ['current_password' => 'A brand new password 9?', 'new_password' => 'Another new password 8!']));
        self::assertSame('', $quiet->getHeaderLine('Set-Cookie'), 'no cookie to remove');
    }

    public function testTheEmailPageAsksForTheNewAddressAndThePassword(): void
    {
        $ada = $this->auth->user();

        $html = (string) $this->emailHandler()->handle($this->request($ada, 'GET', '/account/email'))->getBody();

        self::assertStringContainsString('<h1>Change your email address</h1>', $html);
        self::assertStringContainsString('You sign in with ada@example.org now.', $html);
        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
        self::assertStringContainsString('>Send the code</button>', $html);
    }

    public function testTheNewAddressMustBeNewAllowedAndTheEmailComesOnlyWithTheRightPassword(): void
    {
        $ada = $this->auth->user();

        $same = (string) $this->changeEmail($ada, ' ADA@example.org ', AuthFixture::PASSWORD)->getBody();
        $wrong = (string) $this->changeEmail($ada, 'ada@new.example', 'Not my password 1!')->getBody();
        $refused = (string) $this->emailHandler(new class () implements EmailPolicy {
            public function problem(string $email): string
            {
                return 'Use your work address.';
            }
        })->handle($this->request($ada, 'POST', '/account/email', ['new_email' => 'ada@new.example', 'current_password' => AuthFixture::PASSWORD]))->getBody();

        self::assertStringContainsString('That is already your email address. Enter the new one.', $same);
        self::assertStringContainsString('Your current password is not right.', $wrong);
        self::assertStringContainsString('Use your work address.', $refused);
        self::assertSame([], $this->auth->sent);
        self::assertNull(CodeSession::pending($this->session, VerificationPurpose::EmailChange));

        for ($i = 0; $i < 4; ++$i) {
            $this->changeEmail($ada, 'ada@new.example', 'Not my password 1!');
        }
        self::assertStringContainsString('You have typed a wrong password too many times.', (string) $this->changeEmail($ada, 'ada@new.example', AuthFixture::PASSWORD)->getBody());
    }

    public function testTheAddressChangesOnlyAfterTheCodeAndTheOldAddressIsToldWithoutALink(): void
    {
        $ada = $this->auth->user();

        $response = $this->changeEmail($ada, 'Ada@New.example', AuthFixture::PASSWORD);
        self::assertSame('/account/email/code', $response->getHeaderLine('Location'));
        self::assertSame('ada@new.example', CodeSession::pending($this->session, VerificationPurpose::EmailChange));
        self::assertSame('ada@example.org', $ada->email(), 'nothing changes before the code');
        self::assertCount(1, $this->auth->sent);
        $codeEmail = $this->sent(0);
        self::assertSame('ada@new.example', $codeEmail->getTo()[0]->getAddress());
        self::assertSame('Your code to confirm your new email address for Demo', $codeEmail->getSubject());
        self::assertNoLink($codeEmail);

        $page = $this->codeHandler()->handle($this->request($ada, 'GET', '/account/email/code'));
        $html = (string) $page->getBody();
        self::assertStringContainsString('<h1>Confirm your new email address</h1>', $html);
        self::assertStringContainsString('We sent it by email to a•••@new.example.', $html);
        self::assertStringContainsString('The code works for 15 minutes, and only once.', $html);
        self::assertStringContainsString('name="resend"', $html);

        $wrong = $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['code' => '000000' === AuthFixture::codeIn($codeEmail) ? '111111' : '000000']));
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString(htmlspecialchars(ChangeEmailCodeHandler::WRONG, ENT_QUOTES), (string) $wrong->getBody());
        self::assertSame(422, $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['code' => '']))->getStatusCode());

        $done = $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['code' => AuthFixture::codeIn($codeEmail)]));
        self::assertSame('/account', $done->getHeaderLine('Location'));
        self::assertSame('ada@new.example', $this->auth->users->find((int) $ada->id())?->email());
        self::assertNull(CodeSession::pending($this->session, VerificationPurpose::EmailChange));
        self::assertEquals([new AccountChanged((int) $ada->id(), $ada->id(), ['email'])], $this->events->events);
        self::assertSame(['Your email address is now ada@new.example. Sign in with it from now on.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertCount(2, $this->auth->sent);
        $notice = $this->sent(1);
        self::assertSame('ada@example.org', $notice->getTo()[0]->getAddress());
        self::assertSame('The email address of your account on Demo has changed', $notice->getSubject());
        self::assertStringContainsString('is now a•••@new.example.', (string) $notice->getTextBody());
        self::assertNoLink($notice);

        self::assertSame('/account/email', $this->codeHandler()->handle($this->request($ada, 'GET', '/account/email/code'))->getHeaderLine('Location'), 'without a code on its way, back to the start');
    }

    public function testAnAddressThatHasAnAccountGetsTheSameAnswerButNoCode(): void
    {
        $ada = $this->auth->user();
        $this->auth->user('max@example.org');

        $response = $this->changeEmail($ada, 'max@example.org', AuthFixture::PASSWORD);

        self::assertSame('/account/email/code', $response->getHeaderLine('Location'));
        self::assertSame([], $this->auth->sent);
        self::assertSame(422, $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['code' => '123456']))->getStatusCode());
        self::assertSame('ada@example.org', $ada->email());
    }

    public function testACodeForSomeoneElseOrAnAddressTakenMeanwhileChangesNothing(): void
    {
        $ada = $this->auth->user();
        $max = $this->auth->user('max@example.org');

        $this->changeEmail($ada, 'shared@example.org', AuthFixture::PASSWORD);
        $code = AuthFixture::codeIn($this->sent(0));
        $asMax = $this->codeHandler()->handle($this->request($max, 'POST', '/account/email/code', ['code' => $code]));
        self::assertSame(422, $asMax->getStatusCode(), 'the code was made for Ada');

        $this->changeEmail($ada, 'shared@example.org', AuthFixture::PASSWORD);
        self::assertSame(429, $this->changeEmail($ada, 'shared@example.org', AuthFixture::PASSWORD)->getStatusCode(), 'one code a minute');
        $this->auth->clock->advance(61);
        $this->changeEmail($ada, 'shared@example.org', AuthFixture::PASSWORD);
        $code = AuthFixture::codeIn($this->sent(count($this->auth->sent) - 1));
        $this->auth->user('shared@example.org');
        $taken = $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['code' => $code]));
        self::assertSame(422, $taken->getStatusCode());
        self::assertSame('ada@example.org', $ada->email());
        self::assertSame([], $this->events->events);
    }

    public function testANewCodeOnceAMinuteAndTooManyTriesAreStopped(): void
    {
        $ada = $this->auth->user();
        $this->changeEmail($ada, 'ada@new.example', AuthFixture::PASSWORD);

        $soon = $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['resend' => '1']));
        self::assertSame(429, $soon->getStatusCode());
        self::assertStringContainsString('then send a new code. The code we sent before still works.', (string) $soon->getBody());

        $this->auth->clock->advance(61);
        $again = $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['resend' => '1']));
        self::assertSame('/account/email/code', $again->getHeaderLine('Location'));
        self::assertSame(['We have sent a new code to a•••@new.example. The code we sent before no longer works.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertCount(2, $this->auth->sent);

        for ($i = 0; $i < 3; ++$i) {
            $this->auth->clock->advance(61);
            $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['resend' => '1']));
        }
        $this->auth->clock->advance(61);
        $limited = $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['resend' => '1']));
        self::assertStringContainsString('You have asked for a code too many times.', (string) $limited->getBody());
        $this->auth->clock->advance(61);
        self::assertStringContainsString('You have asked for a code too many times.', (string) $this->changeEmail($ada, 'ada@new.example', AuthFixture::PASSWORD)->getBody());

        $last = 0;
        for ($i = 0; $i < 11; ++$i) {
            $last = $this->codeHandler()->handle($this->request($ada, 'POST', '/account/email/code', ['code' => '000001']));
        }
        self::assertInstanceOf(ResponseInterface::class, $last);
        self::assertSame(429, $last->getStatusCode());
        self::assertStringContainsString('You have tried a code too many times.', (string) $last->getBody());
    }

    private function sent(int $index): Email
    {
        $email = $this->auth->sent[$index] ?? null;
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private static function assertNoLink(Email $email): void
    {
        foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody()] as $body) {
            self::assertDoesNotMatchRegularExpression('#https?://|www\.|<a\s|href=|<img#i', $body);
        }
    }

    private function changePassword(User $user, string $current, string $new): ResponseInterface
    {
        return $this->passwordHandler()->handle($this->request($user, 'POST', '/account/password', ['current_password' => $current, 'new_password' => $new]));
    }

    private function changeEmail(User $user, string $email, string $password): ResponseInterface
    {
        return $this->emailHandler()->handle($this->request($user, 'POST', '/account/email', ['new_email' => $email, 'current_password' => $password]));
    }

    private function passwordHandler(): ChangePasswordHandler
    {
        return new ChangePasswordHandler($this->auth->pages(), $this->auth->validator(), $this->auth->users, $this->auth->signIn(), $this->auth->passwords(), $this->auth->rememberTokens(), $this->auth->clock, $this->events);
    }

    private function emailChange(EmailPolicy $policy = new AnyEmailPolicy()): EmailChange
    {
        return new EmailChange($this->auth->users, $this->auth->codes(), $policy, $this->auth, $this->auth->templates, $this->auth->site, $this->auth->translations, $this->auth->users, $this->auth->clock);
    }

    private function emailHandler(EmailPolicy $policy = new AnyEmailPolicy()): ChangeEmailHandler
    {
        return new ChangeEmailHandler($this->auth->pages(), $this->auth->validator(), $this->auth->users, $this->auth->signIn(), $this->emailChange($policy), $this->auth->timing());
    }

    private function codeHandler(): ChangeEmailCodeHandler
    {
        return new ChangeEmailCodeHandler($this->auth->pages(), $this->auth->validator(), $this->auth->users, $this->emailChange(), $this->auth->timing(), $this->events);
    }

    /**
     * @param array<string, string> $body
     */
    private function request(?User $user, string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = $this->auth->request($method, 'https://demo.example' . $path, $body, session: $this->session);

        return $user === null ? $request : $request->withAttribute(Actor::class, new Actor($user->id(), []));
    }
}
