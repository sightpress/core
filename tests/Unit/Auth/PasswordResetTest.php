<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
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

    public function testEmailsALinkThatWorksForAnHour(): void
    {
        $user = $this->auth->user();

        self::assertNull($this->auth->passwordReset()->request($this->auth->request('POST'), ' Ada@Example.org'));

        self::assertCount(1, $this->auth->sent);
        $email = $this->auth->sent[0];
        self::assertSame('Reset your password for Demo', $email->getSubject());
        self::assertSame('"Ada" <ada@example.org>', $email->getTo()[0]->toString());
        $text = (string) $email->getTextBody();
        $html = (string) $email->getHtmlBody();
        self::assertSame(1, preg_match('#https://demo\.example/password/reset/([A-Za-z0-9_.-]+)#', $text, $link));
        self::assertSame($user, $this->auth->resetTokens()->verify($link[1]));
        self::assertStringContainsString('Hello Ada,', $text);
        self::assertStringContainsString('The link works for 1 hour and only once.', $text);
        self::assertStringContainsString('you can ignore this email', $text);
        self::assertStringContainsString('<a href="' . $link[0] . '"', $html);
        self::assertStringContainsString('<html lang="en-GB">', $html);
    }

    public function testAPendingAccountGetsALinkToChooseItsFirstPassword(): void
    {
        $this->auth->user(pending: true);

        $this->auth->passwordReset()->request($this->auth->request('POST'), 'ada@example.org');

        $email = $this->auth->sent[0];
        self::assertSame('Choose your password for Demo', $email->getSubject());
        self::assertStringContainsString('The link works for 7 days and only once.', (string) $email->getTextBody());
        self::assertStringNotContainsString('ignore this email', (string) $email->getTextBody());
        self::assertStringContainsString('>Choose your password</a>', (string) $email->getHtmlBody());
    }

    public function testUnknownAndDeactivatedAddressesGetNoEmail(): void
    {
        $this->auth->user(deactivated: true);
        $reset = $this->auth->passwordReset();

        self::assertNull($reset->request($this->auth->request('POST'), 'ada@example.org'));
        self::assertNull($reset->request($this->auth->request('POST'), 'grace@example.org'));
        self::assertSame([], $this->auth->sent);
    }

    public function testOnlyThreeLinksAnHourPerAddress(): void
    {
        $this->auth->user();
        $reset = $this->auth->passwordReset();
        $request = new \Nyholm\Psr7\ServerRequest('POST', 'https://demo.example/password/forgot');
        for ($i = 0; $i < 3; ++$i) {
            self::assertNull($reset->request($request, 'ada@example.org'));
        }

        $wait = $reset->request($request, 'ada@example.org');

        self::assertNotNull($wait);
        self::assertGreaterThan(60, $wait);
        self::assertCount(3, $this->auth->sent);
    }

    public function testTheLinkKeepsAPortThatIsNotTheDefault(): void
    {
        $user = $this->auth->user();

        self::assertStringStartsWith(
            'http://localhost:8080/password/reset/',
            $this->auth->passwordReset()->link($this->auth->request(uri: 'http://localhost:8080/password/forgot'), $user),
        );
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

    public function testDescribesHowLongALinkWorks(): void
    {
        self::assertSame('1 hour', PasswordReset::duration(3600));
        self::assertSame('2 hours', PasswordReset::duration(7200));
        self::assertSame('1 hour', PasswordReset::duration(60));
        self::assertSame('1 day', PasswordReset::duration(86400));
        self::assertSame('7 days', PasswordReset::duration(604800));
        self::assertSame('36 hours', PasswordReset::duration(129600));
    }
}
