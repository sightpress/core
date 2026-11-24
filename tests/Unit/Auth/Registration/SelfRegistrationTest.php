<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Registration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Module\Event\AccountRegistered;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Registration\RegistrationDetails;
use SightPress\Core\Auth\Registration\RegistrationMailer;
use SightPress\Core\Auth\Registration\SelfRegistration;
use SightPress\Core\Rbac\RegistrationRole;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\User\AvatarKind;
use Symfony\Component\Mime\Email;

#[CoversClass(SelfRegistration::class)]
#[CoversClass(RegistrationMailer::class)]
#[CoversClass(RegistrationDetails::class)]
#[CoversClass(\SightPress\Core\Auth\Registration\TenantDomainRegistration::class)]
#[UsesNamespace('SightPress\Core')]
final class SelfRegistrationTest extends TestCase
{
    private const string NO_LINK = '#https?://|<a\b|www\.|demo\.example|<img\b#i';

    private AuthFixture $auth;

    private RecordingEvents $events;

    private SelfRegistration $registration;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->events = new RecordingEvents();
        $this->registration = $this->auth->selfRegistration($this->events);
    }

    public function testIsClosedUntilAnAdminOpensIt(): void
    {
        self::assertFalse($this->registration->isOpen());

        $this->auth->database->settings()->set(SignUp::SETTING, '1');
        self::assertTrue($this->registration->isOpen());

        $this->auth->database->settings()->set(SignUp::SETTING, '0');
        self::assertFalse($this->registration->isOpen());
    }

    public function testOnAMembersOnlySitePeopleAtTheOrganisationsDomainsMaySignUp(): void
    {
        $tenant = ['email_policy' => ['domains' => ['example.org', 'example.com']], 'domain_registration' => ['domains' => ['example.org']]];
        $settings = $this->auth->database->settings();
        $registration = $this->auth->selfRegistration($this->events, $tenant);
        $domains = $this->auth->domainRegistration($tenant);

        self::assertNull($domains->offered(), 'a public site');
        self::assertFalse($registration->isOpen());

        $settings->set(\SightPress\Core\Site\Contract\SiteVisibility::SETTING, 'members');
        self::assertSame(['example.org'], $domains->offered());
        self::assertSame(['example.org'], $domains->domains());
        self::assertTrue($registration->isOpen());
        self::assertFalse($registration->isOpenToAnyone());
        self::assertSame(['example.org'], $registration->onlyDomains());
        self::assertNull($registration->problem('Nia@EXAMPLE.org'));
        foreach (['nia@example.com', 'nia@mail.example.org', 'nia@evil-example.org', 'nia@example.org.evil.test'] as $refused) {
            self::assertSame('Only people with an email address at example.org can create an account here. Use your address there.', (string) $registration->problem($refused), $refused);
        }

        $settings->set(SignUp::SETTING, '1');
        self::assertNull($registration->onlyDomains(), 'sign-up open to anyone is not affected');
        self::assertNull($registration->problem('nia@example.com'));

        $settings->set(SignUp::SETTING, '0');
        $settings->set(\SightPress\Core\Auth\Contract\DomainRegistration::SETTING, 'off');
        self::assertTrue($domains->isTurnedOff());
        self::assertSame(['example.org'], $domains->offered());
        self::assertNull($domains->domains());
        self::assertFalse($registration->isOpen());
        self::assertFalse($this->auth->domainRegistration()->isTurnedOff() && $this->auth->domainRegistration()->domains() !== null);
        self::assertNull($this->auth->domainRegistration()->offered(), 'off for the tenant');
    }

    public function testEmailsACodeWithoutALinkAndTheAccountCantSignInUntilItIsTyped(): void
    {
        $this->registration->start($this->auth->request('POST'), $this->details());

        $user = $this->auth->users->findByEmail('nia@example.org');
        self::assertNotNull($user);
        self::assertTrue($user->awaitsEmailVerification());
        self::assertTrue($this->auth->hasher->verify(AuthFixture::PASSWORD, $user->passwordHash()));
        self::assertNull($user->avatarKind());
        self::assertCount(1, $this->auth->sent);
        $email = $this->auth->sent[0];
        self::assertSame('Your code to confirm your email address for Demo', $email->getSubject());
        self::assertSame('"Nia" <nia@example.org>', $email->getTo()[0]->toString());
        $text = (string) $email->getTextBody();
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Hello Nia,', $text);
        self::assertStringContainsString('Someone signed up for Demo with this email address.', $text);
        self::assertStringContainsString('The code works for 15 minutes, and only once.', $text);
        self::assertStringContainsString('We will never send you a link.', $html);
        self::assertStringContainsString('<title>Your code to confirm your email address for Demo</title>', $html);
        $code = AuthFixture::codeIn($email);
        self::assertStringNotContainsString($code, $email->getSubject());
        foreach ([$text, $html] as $body) {
            self::assertDoesNotMatchRegularExpression(self::NO_LINK, $body);
        }
        self::assertFalse($this->auth->signIn()->attempt($this->auth->request('POST'), 'nia@example.org', AuthFixture::PASSWORD)->succeeded());
    }

    public function testTheRightCodeConfirmsTheAccountWithTheSignUpRoleAndAnAvatar(): void
    {
        $this->registration->start($this->auth->request('POST'), $this->details());
        $code = AuthFixture::codeIn($this->auth->sent[0]);

        self::assertNull($this->registration->confirm($this->auth->request('POST'), 'nia@example.org', $code === '000000' ? '111111' : '000000'));
        self::assertNull($this->registration->confirm($this->auth->request('POST'), 'someone@example.org', $code));
        $account = $this->registration->confirm($this->auth->request('POST'), ' NIA@example.org ', $code);

        self::assertNotNull($account);
        $user = $this->auth->users->find((int) $account->id());
        self::assertNotNull($user);
        self::assertTrue($user->isActive());
        self::assertSame(AvatarKind::Generated, $user->avatarKind());
        self::assertSame(['member'], array_map(static fn ($role) => $role->name(), $this->auth->database->roles()->rolesOf((int) $account->id())));
        self::assertEquals([new AccountRegistered((int) $account->id(), 'member')], $this->events->events);
        self::assertNull($this->registration->confirm($this->auth->request('POST'), 'nia@example.org', $code), 'a code works once');
        self::assertTrue($this->auth->signIn()->attempt($this->auth->request('POST'), 'nia@example.org', AuthFixture::PASSWORD)->succeeded());
    }

    public function testASafeSignUpRoleIsGivenAndAnUnsafeOneStoredInTheDatabaseGivesMemberAndIsRecorded(): void
    {
        $this->auth->database->registrationRole()->change('contributor');
        $this->assertRegisteredAs('nia@example.org', 'contributor', null);

        $this->auth->database->settings()->set(RegistrationRole::SETTING, 'editor');
        $this->assertRegisteredAs('zoe@example.org', 'member', 'editor');
    }

    public function testAnAddressWithAnAccountGetsANoticeInsteadAndNothingChanges(): void
    {
        $ada = $this->auth->user('nia@example.org', 'editor');
        $ada->changeLocale('es', $this->auth->clock->now());
        $this->auth->users->save($ada);

        $this->registration->start($this->auth->request('POST'), $this->details(password: 'Someone else’s password 9!'));

        self::assertCount(1, $this->auth->sent);
        $email = $this->auth->sent[0];
        self::assertSame('Alguien ha intentado registrarse en Demo con tu dirección de correo', $email->getSubject());
        self::assertStringContainsString('Ya tienes una cuenta allí', (string) $email->getTextBody());
        foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody()] as $body) {
            self::assertDoesNotMatchRegularExpression(self::NO_LINK, $body);
            self::assertDoesNotMatchRegularExpression('/\d{3} \d{3}/', $body, 'no code');
        }
        self::assertTrue($ada->isActive());
        self::assertTrue($this->auth->hasher->verify(AuthFixture::PASSWORD, $ada->passwordHash()), 'the password stays');
        self::assertSame('Ada', $ada->firstName());

        $this->auth->clock->advance(61);
        $this->registration->resend($this->auth->request('POST'), 'nia@example.org');
        self::assertCount(1, $this->auth->sent, 'no new code for an account that is already confirmed');
    }

    public function testAskingForCodesTooOftenIsLimitedForEveryAddressAlike(): void
    {
        $this->auth->user('ada@example.org');
        foreach (['nia@example.org', 'ada@example.org'] as $email) {
            $this->registration->start($this->auth->request('POST'), $this->details($email));
            try {
                $this->registration->start($this->auth->request('POST'), $this->details($email));
                self::fail('A second code within a minute for ' . $email);
            } catch (TooManyCodes $exception) {
                self::assertTrue($exception->tooSoon, $email);
            }
        }
    }

    public function testANewCodeReplacesTheOldOne(): void
    {
        $this->registration->start($this->auth->request('POST'), $this->details());
        $this->auth->clock->advance(61);
        $this->registration->resend($this->auth->request('POST'), 'nia@example.org');

        self::assertCount(2, $this->auth->sent);
        $older = AuthFixture::codeIn($this->auth->sent[0]);
        $newer = AuthFixture::codeIn($this->auth->sent[1]);
        if ($older !== $newer) {
            self::assertNull($this->registration->confirm($this->auth->request('POST'), 'nia@example.org', $older));
        }
        self::assertNotNull($this->registration->confirm($this->auth->request('POST'), 'nia@example.org', $newer));

        $this->auth->clock->advance(61);
        $this->registration->resend($this->auth->request('POST'), 'nobody@example.org');
        self::assertCount(2, $this->auth->sent);
    }

    public function testACodeForAnAccountThatWasRemovedMeanwhileDoesNothing(): void
    {
        $this->registration->start($this->auth->request('POST'), $this->details());
        $code = AuthFixture::codeIn($this->auth->sent[0]);
        $user = $this->auth->users->findByEmail('nia@example.org');
        self::assertNotNull($user);
        $user->confirmEmail($this->auth->clock->now());
        $this->auth->users->save($user);

        self::assertNull($this->registration->confirm($this->auth->request('POST'), 'nia@example.org', $code));
        self::assertSame([], $this->events->events);
    }

    public function testTooManyWrongCodesAreRefused(): void
    {
        $this->registration->start($this->auth->request('POST'), $this->details());
        for ($i = 0; $i < 10; ++$i) {
            $this->registration->confirm($this->auth->request('POST'), 'zoe' . $i . '@example.org', '123456');
        }

        $this->expectException(TooManyCodes::class);
        $this->registration->confirm($this->auth->request('POST'), 'nia@example.org', '123456');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function shippedLanguages(): iterable
    {
        yield 'British English' => ['en-GB', 'Your code to confirm your email address for Demo', 'We will never send you a link.'];
        yield 'European Portuguese' => ['pt-PT', 'O seu código para confirmar o seu endereço de email em Demo', 'Nunca lhe enviaremos uma ligação.'];
        yield 'Spanish' => ['es', 'Tu código para confirmar tu dirección de correo en Demo', 'Nunca te enviaremos un enlace.'];
    }

    #[DataProvider('shippedLanguages')]
    public function testTheCodeIsInThePersonsLanguageAndNeverHasALink(string $locale, string $subject, string $neverALink): void
    {
        $this->registration->start($this->auth->request('POST'), $this->details(locale: $locale));

        $email = $this->auth->sent[0];
        self::assertSame($subject, $email->getSubject());
        self::assertStringContainsString($neverALink, (string) $email->getTextBody());
        self::assertStringContainsString('<html lang="' . $locale . '" dir="ltr">', (string) $email->getHtmlBody());
        foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody(), $email->getSubject()] as $body) {
            self::assertDoesNotMatchRegularExpression(self::NO_LINK, $body);
        }
    }

    public function testTheClientAddressMayBeUnknown(): void
    {
        $request = new \Nyholm\Psr7\ServerRequest('POST', 'https://demo.example/register');
        $this->registration->start($request, $this->details());

        self::assertCount(1, $this->auth->sent);
    }

    private function assertRegisteredAs(string $email, string $role, ?string $refused): void
    {
        $this->auth->sent = [];
        $this->events->events = [];
        $this->registration->start($this->auth->request('POST'), $this->details($email));
        $account = $this->registration->confirm($this->auth->request('POST'), $email, AuthFixture::codeIn($this->sent()));

        self::assertNotNull($account);
        self::assertSame([$role], array_map(static fn ($r) => $r->name(), $this->auth->database->roles()->rolesOf((int) $account->id())));
        self::assertEquals([new AccountRegistered((int) $account->id(), $role, $refused)], $this->events->events);
    }

    private function sent(): Email
    {
        return $this->auth->sent[0];
    }

    private function details(string $email = 'nia@example.org', string $password = AuthFixture::PASSWORD, ?string $locale = null): RegistrationDetails
    {
        return new RegistrationDetails($email, 'Nia', 'Lind', null, 'Europe/Lisbon', $locale, $password);
    }
}
