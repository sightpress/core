<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Phone;

use SightPress\Core\Infrastructure\Http\OutboundHttp;
use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\Http\ChangePasswordHandler;
use SightPress\Core\Auth\Http\ChoosePasswordHandler;
use SightPress\Core\Auth\Http\ForgotPasswordHandler;
use SightPress\Core\Auth\Http\LoginHandler;
use SightPress\Core\Auth\Http\ResetCodeHandler;
use SightPress\Core\Auth\PasswordChangedNotice;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Auth\SignInIdentifier;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\RecordingMail;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Signing in and resetting the password with a mobile number (N-5), through the whole kernel.
 */
#[CoversClass(SignInIdentifier::class)]
#[CoversClass(SignIn::class)]
#[CoversClass(LoginHandler::class)]
#[CoversClass(ForgotPasswordHandler::class)]
#[CoversClass(ResetCodeHandler::class)]
#[CoversClass(ChoosePasswordHandler::class)]
#[CoversClass(ChangePasswordHandler::class)]
#[CoversClass(PasswordReset::class)]
#[CoversClass(PasswordChangedNotice::class)]
#[UsesNamespace('SightPress\Core')]
final class PhoneSignInTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    private RecordingMail $mail;

    private bool $providerDown = false;

    private \SightPress\Core\Tests\Support\FixedClock $clock;

    /** @var list<array{to: string, text: string}> */
    private array $texts = [];

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    private function boot(bool $on = true): void
    {
        $this->site = new KernelSite('phone_sign_in', tenant: ['sms' => ['provider' => 'twilio', 'account' => 'AC' . '0123456789abcdef0123456789abcdef', 'sender' => 'SightPress']], variables: ['SMS_API_KEY' => 'canary5f2b9c1d7e4a']);
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $tenant);
        $this->mail = new RecordingMail();
        $this->clock = new \SightPress\Core\Tests\Support\FixedClock(new DateTimeImmutable());
        $tenant->set(\Psr\Clock\ClockInterface::class, $this->clock);
        $tenant->set(MailSender::class, $this->mail);
        $tenant->set(OutboundHttp::INNER, new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ($this->providerDown) {
                return new MockResponse('', ['http_code' => 503]);
            }
            parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);
            $this->texts[] = ['to' => is_string($form['To'] ?? null) ? $form['To'] : '', 'text' => is_string($form['Body'] ?? null) ? $form['Body'] : ''];

            return new MockResponse('{"sid":"SM' . count($this->texts) . '"}', ['http_code' => 201]);
        }));
        if ($on) {
            Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'sms', null);
            Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        }
    }

    public function testSignsInWithANationalOrInternationalNumber(): void
    {
        $this->boot();
        $this->person('max@example.org', 'member', '+447400123456');
        $page = (string) new Browser($this->site->kernel, 'http://example.org')->request('GET', '/login')->getBody();
        self::assertStringContainsString('Email address or mobile number', $page);
        self::assertStringContainsString('Mobile numbers can start with + and the country code.', $page);
        self::assertStringContainsString('type="text"', $page);

        foreach (['07400 123456', '+44 7400 123456', 'max@example.org'] as $identifier) {
            $signedIn = new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => $identifier, 'password' => self::PASSWORD]);
            self::assertSame([303, '/'], [$signedIn->getStatusCode(), $signedIn->getHeaderLine('Location')], $identifier);
        }
    }

    public function testUnknownAndUnconfirmedNumbersGetTheSameAnswerAsAWrongEmail(): void
    {
        $this->boot();
        $this->person('max@example.org', 'member');
        $wrong = (string) new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => 'nobody@example.org', 'password' => self::PASSWORD])->getBody();
        foreach (['07400 123456', '+447400999999', 'not a number'] as $identifier) {
            $response = new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => $identifier, 'password' => self::PASSWORD]);
            self::assertSame(422, $response->getStatusCode(), $identifier);
            self::assertStringContainsString('Enter a correct email address or mobile number, and password.', (string) $response->getBody());
        }
        self::assertStringContainsString('Enter a correct email address or mobile number, and password.', $wrong);
    }

    public function testFailuresWithTheEmailAndTheNumberShareOneLimit(): void
    {
        $this->boot();
        $this->person('max@example.org', 'member', '+447400123456');
        $browser = new Browser($this->site->kernel, 'http://example.org');
        foreach (['max@example.org', '07400 123456', 'MAX@example.org', '+447400123456', 'max@example.org'] as $identifier) {
            self::assertSame(422, $browser->submit('/login', ['email' => $identifier, 'password' => 'wrong password'])->getStatusCode());
        }
        $limited = $browser->submit('/login', ['email' => '07400 123456', 'password' => self::PASSWORD]);
        self::assertSame(429, $limited->getStatusCode());
        self::assertStringContainsString('You have tried to sign in too many times.', (string) $limited->getBody());
    }

    public function testAnOldNumberStopsWorkingAtOnce(): void
    {
        $this->boot();
        $id = $this->person('max@example.org', 'member', '+447400123456');
        Services::get($this->site->container(), UserRepository::class)->confirmPhone($id, '+447400123457', null);
        self::assertSame(422, new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => '07400 123456', 'password' => self::PASSWORD])->getStatusCode());
        self::assertSame(303, new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => '07400 123457', 'password' => self::PASSWORD])->getStatusCode());
    }

    public function testWithTextMessagesOffItIsEmailAgainAndNumbersNeverMatch(): void
    {
        $this->boot(on: false);
        $this->person('max@example.org', 'member', '+447400123456');
        $page = (string) new Browser($this->site->kernel, 'http://example.org')->request('GET', '/login')->getBody();
        self::assertStringContainsString('>Email address<', $page);
        self::assertStringNotContainsString('mobile number', $page);
        $refused = new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => '+447400123456', 'password' => self::PASSWORD]);
        self::assertSame(422, $refused->getStatusCode(), 'an email address is needed');
        self::assertStringContainsString(LoginHandler::WRONG, (string) new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => 'max@example.org', 'password' => 'wrong'])->getBody());
        self::assertStringNotContainsString('mobile number', (string) new Browser($this->site->kernel, 'http://example.org')->request('GET', '/login/forgot')->getBody());
    }

    public function testResetsThePasswordWithACodeByTextAndTheEmailSaysSo(): void
    {
        $this->boot();
        $this->person('max@example.org', 'member', '+447400123456');
        $browser = new Browser($this->site->kernel, 'http://example.org');
        self::assertStringContainsString('Email address or mobile number', (string) $browser->request('GET', '/login/forgot')->getBody());
        $asked = $browser->submit('/login/forgot', ['email' => '07400 123456']);
        self::assertSame([303, '/login/forgot/code'], [$asked->getStatusCode(), $asked->getHeaderLine('Location')]);
        self::assertCount(1, $this->texts);
        self::assertMatchesRegularExpression('/^\d{3} \d{3} is your code to reset your password for Demo\. It works for 10 minutes\. Never share it\.$/', $this->texts[0]['text']);
        $page = (string) $browser->request('GET', '/login/forgot/code')->getBody();
        self::assertStringContainsString('We sent it by text message to •••• •••• 3456.', $page);
        self::assertStringContainsString('Use your email address or another number', $page);

        self::assertSame(429, $browser->submit('/login/forgot/code', ['resend' => '1'])->getStatusCode(), 'once a minute');
        self::assertSame(422, $browser->submit('/login/forgot/code', ['code' => '000000'])->getStatusCode());
        $code = str_replace(' ', '', substr($this->texts[0]['text'], 0, 7));
        self::assertSame(303, $browser->submit('/login/forgot/code', ['code' => $code])->getStatusCode());
        $chosen = $browser->submit('/login/password', ['password' => 'A brand new long password 9!']);
        self::assertSame([303, '/login'], [$chosen->getStatusCode(), $chosen->getHeaderLine('Location')]);
        self::assertSame(['The password of your account on Demo was changed'], $this->mail->subjectsTo('max@example.org'));
        $notice = (string) $this->mail->sent[0]->getTextBody();
        self::assertStringContainsString('after a code we sent to your mobile number', $notice);
        self::assertStringNotContainsString('http', $notice);
        self::assertSame(303, new Browser($this->site->kernel, 'http://example.org')->submit('/login', ['email' => '07400 123456', 'password' => 'A brand new long password 9!'])->getStatusCode());
    }

    public function testPeopleWhoManageTheSiteNeverResetByText(): void
    {
        $this->boot();
        $this->person('ada@example.org', 'admin', '+447400123456');
        $browser = new Browser($this->site->kernel, 'http://example.org');
        self::assertSame(303, $browser->submit('/login/forgot', ['email' => '+44 7400 123456'])->getStatusCode(), 'the same answer');
        self::assertSame([], $this->texts, 'no text to an admin');
        self::assertStringContainsString('People who manage the site reset their password by email only', (string) $browser->request('GET', '/login/forgot')->getBody());
        self::assertSame(303, $browser->submit('/login/forgot', ['email' => '07400 000000'])->getStatusCode(), 'an unknown number too');
        self::assertSame([], $this->texts);
    }

    public function testANewCodeByTextAndSomeoneWhoBecameAnAdminMeanwhile(): void
    {
        $this->boot();
        $id = $this->person('max@example.org', 'member', '+447400123456');
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $browser->submit('/login/forgot', ['email' => '07400 123456']);
        $this->clock->advance(61);
        self::assertSame(303, $browser->submit('/login/forgot/code', ['resend' => '1'])->getStatusCode());
        self::assertStringContainsString('We have sent a new code to •••• •••• 3456.', (string) $browser->request('GET', '/login/forgot/code')->getBody());
        self::assertCount(2, $this->texts);

        $roles = Services::get($this->site->container(), RoleRepository::class);
        $roles->assign($id, $roles->findRole('admin') ?? throw new \LogicException('admin'));
        $code = str_replace(' ', '', substr($this->texts[1]['text'], 0, 7));
        self::assertSame(422, $browser->submit('/login/forgot/code', ['code' => $code])->getStatusCode(), 'an admin now: email only');
    }

    public function testWhenTheCodeCannotBeSentTheAnswerIsTheSame(): void
    {
        $this->boot();
        $this->person('max@example.org', 'member', '+447400123456');
        $this->providerDown = true;
        $asked = new Browser($this->site->kernel, 'http://example.org')->submit('/login/forgot', ['email' => '07400 123456']);
        self::assertSame([303, '/login/forgot/code'], [$asked->getStatusCode(), $asked->getHeaderLine('Location')]);
        self::assertSame([], $this->texts);
    }

    public function testChangingThePasswordOnMyAccountSendsTheNoticeToo(): void
    {
        $this->boot();
        $this->person('max@example.org', 'member', '+447400123456');
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $browser->submit('/login', ['email' => 'max@example.org', 'password' => self::PASSWORD]);
        $changed = $browser->submit('/account/password', ['current_password' => self::PASSWORD, 'new_password' => 'Another long new password 4!']);
        self::assertSame(303, $changed->getStatusCode());
        self::assertSame(['The password of your account on Demo was changed'], $this->mail->subjectsTo('max@example.org'));
        self::assertStringContainsString('was just changed.', (string) $this->mail->sent[0]->getTextBody());
    }

    private function person(string $email, string $role, ?string $phone = null): int
    {
        $users = Services::get($this->site->container(), UserRepository::class);
        $user = new User($email, 'Max', 'Müller', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $user->activate(new DateTimeImmutable());
        $users->save($user);
        $id = (int) $user->id();
        $roles = Services::get($this->site->container(), RoleRepository::class);
        $roles->assign($id, $roles->findRole($role) ?? throw new \LogicException($role));
        if ($phone !== null) {
            $users->confirmPhone($id, $phone, 'mobile GB');
        }

        return $id;
    }
}
