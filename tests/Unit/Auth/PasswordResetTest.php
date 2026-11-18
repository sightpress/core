<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\User\UserStatus;

#[CoversClass(PasswordReset::class)]
#[UsesNamespace('SightPress\Core')]
final class PasswordResetTest extends TestCase
{
    private AuthFixture $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
    }

    public function testEmailsACodeAndNeverALink(): void
    {
        $user = $this->auth->user();

        $this->auth->passwordReset()->request($this->auth->request('POST'), ' Ada@Example.org');

        self::assertCount(1, $this->auth->sent);
        $email = $this->auth->sent[0];
        self::assertSame('Your code to reset your password for Demo', $email->getSubject());
        self::assertSame('"Ada" <ada@example.org>', $email->getTo()[0]->toString());
        $text = (string) $email->getTextBody();
        $html = (string) $email->getHtmlBody();
        $code = AuthFixture::codeIn($email);
        self::assertStringNotContainsString($code, $email->getSubject());
        self::assertStringContainsString(substr($code, 0, 3) . ' ' . substr($code, 3), $html);
        self::assertStringContainsString('Hello Ada,', $text);
        self::assertStringContainsString('The code works for 15 minutes, and only once.', $text);
        self::assertStringContainsString('We will never send you a link. Type this code on the page where you asked for it. Never share it with anyone.', $text);
        self::assertStringContainsString('you can ignore this email', $text);
        self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $html);
        foreach ([$text, $html] as $body) {
            self::assertDoesNotMatchRegularExpression('#https?://|<a\b|www\.|demo\.example|<img\b#i', $body);
        }
        self::assertSame($user, $this->auth->passwordReset()->verify($this->auth->request('POST'), VerificationPurpose::PasswordReset, 'ada@example.org', $code));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function shippedLanguages(): iterable
    {
        yield 'British English' => ['en-GB', 'Your code to reset your password for Demo', 'The code works for 15 minutes, and only once.', 'We will never send you a link.'];
        yield 'European Portuguese' => ['pt-PT', 'O seu código para repor a palavra-passe em Demo', 'O código funciona durante 15 minutos e só uma vez.', 'Nunca lhe enviaremos uma ligação.'];
        yield 'Spanish' => ['es', 'Tu código para restablecer tu contraseña en Demo', 'El código funciona durante 15 minutos y solo una vez.', 'Nunca te enviaremos un enlace.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shippedLanguages')]
    public function testTheEmailIsInThePersonsLanguageAndNeverHasALink(string $locale, string $subject, string $validFor, string $neverALink): void
    {
        $user = $this->auth->user();
        $user->changeLocale($locale, $this->auth->clock->now());
        $this->auth->users->save($user);

        $this->auth->passwordReset()->request($this->auth->request('POST'), 'ada@example.org');

        $email = $this->auth->sent[0];
        self::assertSame($subject, $email->getSubject());
        $text = (string) $email->getTextBody();
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString($validFor, $text);
        self::assertStringContainsString($neverALink, $text);
        self::assertStringContainsString('<html lang="' . $locale . '" dir="ltr">', $html);
        foreach ([$text, $html, $email->getSubject()] as $body) {
            self::assertDoesNotMatchRegularExpression('#https?://|<a\b|www\.|demo\.example|<img\b#i', $body);
        }
    }

    public function testAPendingAccountGetsACodeToChooseItsFirstPassword(): void
    {
        $this->auth->user(pending: true);

        $this->auth->passwordReset()->request($this->auth->request('POST'), 'ada@example.org');

        $email = $this->auth->sent[0];
        self::assertSame('Your code to choose your password for Demo', $email->getSubject());
        self::assertStringContainsString('your new account on Demo', (string) $email->getTextBody());
        self::assertStringContainsString('your new account on Demo', (string) $email->getHtmlBody());
    }

    public function testUnknownAndDeactivatedAddressesGetNoEmailButTheSameLimits(): void
    {
        $this->auth->user(deactivated: true);
        $reset = $this->auth->passwordReset();

        $reset->request($this->auth->request('POST'), 'ada@example.org');
        $reset->request($this->auth->request('POST'), 'grace@example.org');
        self::assertSame([], $this->auth->sent);

        $this->expectException(TooManyCodes::class);
        $reset->request($this->auth->request('POST'), 'grace@example.org');
    }

    public function testACodeProvesOnlyTheAccountItWasMadeFor(): void
    {
        $ada = $this->auth->user();
        $max = $this->auth->user('max@example.org', pending: true);
        $codes = $this->auth->codes();
        $reset = $this->auth->passwordReset();
        $request = $this->auth->request('POST');

        $code = $codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org', (int) $max->id());
        self::assertNull($reset->verify($request, VerificationPurpose::PasswordReset, 'ada@example.org', $code->code), 'the code names another account');

        $code = $codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'nobody@example.org');
        self::assertNull($reset->verify($request, VerificationPurpose::PasswordReset, 'nobody@example.org', $code->code), 'no account');

        $code = $codes->issue(VerificationPurpose::AccountSetup, VerificationChannel::Email, 'ada@example.org', (int) $ada->id());
        self::assertNull($reset->verify($request, VerificationPurpose::AccountSetup, 'ada@example.org', $code->code), 'setup codes only work for pending accounts');

        $code = $codes->issue(VerificationPurpose::AccountSetup, VerificationChannel::Email, 'max@example.org', (int) $max->id());
        self::assertSame($max, $reset->verify($request, VerificationPurpose::AccountSetup, 'Max@example.org', $code->code));

        self::assertNull($reset->verify($request, VerificationPurpose::PasswordReset, 'ada@example.org', '123456'));
    }

    public function testADeactivatedAccountCantUseAResetCode(): void
    {
        $user = $this->auth->user(deactivated: true);
        $code = $this->auth->codes()->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org', (int) $user->id());

        self::assertNull($this->auth->passwordReset()->verify($this->auth->request('POST'), VerificationPurpose::PasswordReset, 'ada@example.org', $code->code));
    }

    public function testTooManyTriesFromOneClientAreRefused(): void
    {
        $reset = $this->auth->passwordReset();
        for ($i = 0; $i < 10; ++$i) {
            $reset->verify($this->auth->request('POST'), VerificationPurpose::PasswordReset, 'ada@example.org', '123456');
        }

        $this->expectException(TooManyCodes::class);
        $reset->verify($this->auth->request('POST'), VerificationPurpose::PasswordReset, 'ada@example.org', '123456');
    }

    public function testTheClientAddress(): void
    {
        self::assertSame('192.0.2.10', PasswordReset::client($this->auth->request()));
        self::assertSame('unknown', PasswordReset::client(new \Nyholm\Psr7\ServerRequest('GET', '/')));
    }

    public function testSettingThePasswordActivatesTheAccountAndForgetsItsDevices(): void
    {
        $user = $this->auth->user(pending: true);
        $tokens = $this->auth->rememberTokens();
        $cookie = explode('=', explode(';', $tokens->issue((int) $user->id(), true))[0], 2)[1];

        $this->auth->passwordReset()->complete($user, 'a long enough new password');

        self::assertSame(UserStatus::Active, $user->status());
        self::assertTrue($this->auth->hasher->verify('a long enough new password', $user->passwordHash()));
        $this->auth->database->entityManager->clear();
        self::assertSame($user->passwordHash(), $this->auth->users->find((int) $user->id())?->passwordHash());
        self::assertNull($tokens->consume($this->auth->request(cookies: [RememberTokens::SECURE_COOKIE => $cookie])));
    }
}
