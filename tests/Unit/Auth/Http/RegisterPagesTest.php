<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Auth\AnyEmailPolicy;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\EmailPolicy;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Http\RegisterCodeHandler;
use SightPress\Core\Auth\Http\RegisterHandler;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\Text;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(RegisterHandler::class)]
#[CoversClass(RegisterCodeHandler::class)]
#[CoversClass(AnyEmailPolicy::class)]
#[CoversClass(SignUp::class)]
#[UsesNamespace('SightPress\Core')]
final class RegisterPagesTest extends TestCase
{
    private AuthFixture $auth;

    private Session $session;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->session = new Session();
        $this->auth->database->settings()->set(SignUp::SETTING, '1');
    }

    public function testThereIsNoSignUpFormWhileTheSiteDoesntLetPeopleSignUp(): void
    {
        $this->auth->database->settings()->set(SignUp::SETTING, '0');

        $this->expectException(HttpNotFoundException::class);
        $this->register();
    }

    public function testAsksForEverythingAnAccountNeedsWithLabelsAndHints(): void
    {
        $response = $this->register(uri: '/register?return=/members');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<title>Create an account on Demo - Demo</title>', $html);
        self::assertStringContainsString('<h1>Create an account on Demo</h1>', $html);
        self::assertStringContainsString('<form id="register" action="/register?return=%2Fmembers" method="post" novalidate>', $html);
        foreach (['register-first-name">First name', 'register-last-name">Last name', 'register-display-name">Display name (optional)', 'register-email">Email address', 'register-timezone">Time zone', 'register-password">Password'] as $label) {
            self::assertStringContainsString('<label for="' . $label . '</label>', $html);
        }
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringContainsString('<option value="Europe/London" selected>London</option>', $html);
        self::assertStringContainsString('At least 12 characters, including', $html);
        self::assertStringNotContainsString('register-locale', $html, 'one language: nothing to choose');
        self::assertStringContainsString('<div hidden>', $html);
        self::assertStringContainsString('name="website" value="" tabindex="-1" autocomplete="off"', $html);
        self::assertMatchesRegularExpression('#<script src="/assets/js/timezone\.js[^"]*" data-select="register-timezone" defer></script>#', $html);
        self::assertStringContainsString('<a href="/login?return=%2Fmembers">Sign in</a>', $html);
        self::assertStringNotContainsString('name="role"', $html);
    }

    public function testOnASiteInSeveralLanguagesThePersonChoosesTheirs(): void
    {
        $site = SiteFixture::site()->withDisplay(null, null, null, ['en-GB', 'pt-PT', 'es']);
        $request = $this->auth->request('GET', 'https://demo.example/register', session: $this->session)->withAttribute(Viewer::class, new Viewer('pt-PT', 'UTC'));

        $html = (string) $this->handler($site)->handle($request)->getBody();

        self::assertStringContainsString('<label for="register-locale">Idioma</label>', $html);
        self::assertStringContainsString('<option value="pt-PT" lang="pt-PT" selected>Português europeu</option>', $html);
        self::assertStringContainsString('<option value="es" lang="es">Español</option>', $html);

        $this->post(['locale' => 'es'], site: $site);
        self::assertSame('es', $this->auth->users->findByEmail('nia@example.org')?->locale());
    }

    public function testSigningUpSendsACodeAndMovesOnToThePageWhereItIsTyped(): void
    {
        $response = $this->post([], '/register?return=/members');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/register/code', $response->getHeaderLine('Location'));
        self::assertSame('nia@example.org', CodeSession::pending($this->session, VerificationPurpose::EmailVerify));
        self::assertSame('/members', $this->session->get(RegisterHandler::RETURN_SESSION));
        $user = $this->auth->users->findByEmail('nia@example.org');
        self::assertNotNull($user);
        self::assertSame(['Nia', 'Lind', null, 'Asia/Tokyo', null], [$user->firstName(), $user->lastName(), $user->displayName(), $user->timezone(), $user->locale()]);
        self::assertCount(1, $this->auth->sent);

        $page = $this->code();
        $html = (string) $page->getBody();
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('noindex, nofollow', $page->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('<title>Confirm your email address - Demo</title>', $html);
        self::assertStringContainsString('If this address can be used for a new account, we have emailed it a 6-digit code. The code works for 15 minutes, and only once.', $html);
        self::assertStringContainsString('We sent it by email to n•••@example.org.', $html);
        self::assertStringContainsString('autocomplete="one-time-code" inputmode="numeric"', $html);
        self::assertStringContainsString('>Confirm my email address</button>', $html);
        self::assertStringContainsString('name="resend"', $html);
        self::assertStringContainsString('<a href="/register">Use another email address</a>', $html);

        $wrong = $this->code(['code' => AuthFixture::codeIn($this->auth->sent[0]) === '000000' ? '111111' : '000000']);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString(RegisterCodeHandler::WRONG, (string) $wrong->getBody());
        self::assertSame(422, $this->code(['code' => '12'])->getStatusCode());

        $done = $this->code(['code' => AuthFixture::codeIn($this->auth->sent[0])]);
        self::assertSame(303, $done->getStatusCode());
        self::assertSame('/login?return=%2Fmembers', $done->getHeaderLine('Location'));
        self::assertSame(['Your email address is confirmed and your account is ready. Sign in with nia@example.org and your password.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertTrue($this->auth->users->findByEmail('nia@example.org')?->isActive());
        self::assertNull($this->session->get(RegisterHandler::RETURN_SESSION));
        self::assertSame('/register', $this->code()->getHeaderLine('Location'), 'done: nothing to type any more');
    }

    public function testAnAddressWithAnAccountGetsTheSameAnswerAndItsOwnerANotice(): void
    {
        $this->auth->user('nia@example.org');

        $response = $this->post();

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/register/code', $response->getHeaderLine('Location'));
        self::assertCount(1, $this->auth->sent);
        self::assertSame('Someone tried to sign up for Demo with your email address', $this->auth->sent[0]->getSubject());
        self::assertStringContainsString('If this address can be used for a new account', (string) $this->code()->getBody());
    }

    public function testARoleSentWithTheFormIsNeverRead(): void
    {
        $this->post(['role' => 'admin', 'roles' => 'admin']);
        $account = $this->auth->selfRegistration()->confirm($this->auth->request('POST'), 'nia@example.org', AuthFixture::codeIn($this->auth->sent[0]));

        self::assertNotNull($account);
        self::assertSame(['member'], array_map(static fn ($role) => $role->name(), $this->auth->database->roles()->rolesOf((int) $account->id())));
    }

    public function testABotThatFillsTheHiddenFieldGetsTheSameAnswerButNothingIsMade(): void
    {
        $response = $this->post(['website' => 'https://spam.example']);

        self::assertSame('/register/code', $response->getHeaderLine('Location'));
        self::assertNull($this->auth->users->findByEmail('nia@example.org'));
        self::assertSame([], $this->auth->sent);
    }

    public function testEveryProblemIsExplainedAndThePasswordIsNeverShownAgain(): void
    {
        $response = $this->post(['first_name' => '', 'email' => 'nia@', 'timezone' => 'Mars/Olympus', 'password' => 'short']);
        $html = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<title>Error: Create an account on Demo - Demo</title>', $html);
        self::assertStringContainsString('<a href="#register-first-name">Enter first name</a>', $html);
        self::assertStringContainsString('Enter an email address in the right format', $html);
        self::assertStringContainsString('Select one of the options for “Time zone”', $html);
        self::assertStringContainsString('Your password must be at least 12 characters long.', $html);
        self::assertStringNotContainsString('value="short"', $html);
        self::assertStringNotContainsString('timezone.js', $html);

        $same = (string) $this->post(['password' => 'nia@example.org'])->getBody();
        self::assertStringContainsString('Your password must not be your email address.', $same);

        $policy = self::createStub(EmailPolicy::class);
        $policy->method('problem')->willReturn(new \SightPress\Core\Template\Message('Only addresses at example.com can join.'));
        $refused = $this->post(policy: $policy);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Only addresses at example.com can join.', (string) $refused->getBody());

        $long = $this->post(['first_name' => str_repeat('N', 101)]);
        self::assertStringContainsString('First name must be 100 characters or fewer', (string) $long->getBody());
        self::assertNull($this->auth->users->findByEmail('nia@example.org'));
    }

    public function testANameOfOnlySpacesIsRefused(): void
    {
        $response = $this->post(['first_name' => ' ']);

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->auth->users->findByEmail('nia@example.org'));
    }

    public function testAFormWithoutTheHiddenFieldIsStillReadAndNoReturnIsKept(): void
    {
        $this->session->set(RegisterHandler::RETURN_SESSION, '/old');
        $body = ['first_name' => 'Nia', 'last_name' => 'Lind', 'email' => 'nia@example.org', 'timezone' => 'UTC', 'password' => AuthFixture::PASSWORD];

        $response = $this->handler()->handle($this->auth->request('POST', 'https://demo.example/register', $body, session: $this->session));

        self::assertSame('/register/code', $response->getHeaderLine('Location'));
        self::assertNotNull($this->auth->users->findByEmail('nia@example.org'));
        self::assertNull($this->session->get(RegisterHandler::RETURN_SESSION));
    }

    public function testTooManySignUpsFromOnePlaceAreRefused(): void
    {
        $limiters = new RateLimiters(new InMemoryStorage(), ['registration' => ['policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour']]);
        self::assertSame(303, $this->post(limiters: $limiters)->getStatusCode());

        $limited = $this->post(['email' => 'zoe@example.org'], limiters: $limiters);

        self::assertSame(429, $limited->getStatusCode());
        self::assertMatchesRegularExpression('/Too many accounts have been made from here\. Wait \d+ minutes and try again\./', (string) $limited->getBody());
        self::assertNull($this->auth->users->findByEmail('zoe@example.org'));
    }

    public function testSigningUpAgainWithinAMinuteGoesOnToTheCodeButTooManyCodesAreRefused(): void
    {
        self::assertSame(303, $this->post()->getStatusCode());
        self::assertSame(303, $this->post()->getStatusCode(), 'the code we sent a moment ago still works');
        self::assertCount(1, $this->auth->sent);
        for ($i = 0; $i < 4; ++$i) {
            $this->auth->clock->advance(61);
            $this->post();
        }
        $this->auth->clock->advance(61);

        $limited = $this->post();

        self::assertSame(429, $limited->getStatusCode());
        self::assertStringContainsString('You have asked for a code too many times.', (string) $limited->getBody());
    }

    public function testSomeoneSignedInDoesntSignUpAgain(): void
    {
        $request = $this->auth->request('GET', 'https://demo.example/register?return=/members', session: $this->session)->withAttribute(Actor::class, new Actor(1));

        self::assertSame('/members', $this->handler()->handle($request)->getHeaderLine('Location'));
    }

    public function testANewCodeIsSentAtMostOnceAMinute(): void
    {
        $this->post();

        $tooSoon = $this->code(['resend' => '1']);
        self::assertSame(429, $tooSoon->getStatusCode());
        self::assertMatchesRegularExpression('/Wait \d+ seconds, then send a new code\. The code we sent before still works\./', (string) $tooSoon->getBody());

        $this->auth->clock->advance(61);
        $resent = $this->code(['resend' => '1']);
        self::assertSame('/register/code', $resent->getHeaderLine('Location'));
        self::assertSame(['We have sent a new code to n•••@example.org. The code we sent before no longer works.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertCount(2, $this->auth->sent);

        for ($i = 0; $i < 3; ++$i) {
            $this->auth->clock->advance(61);
            $this->code(['resend' => '1']);
        }
        $this->auth->clock->advance(61);
        self::assertStringContainsString('You have asked for a code too many times.', (string) $this->code(['resend' => '1'])->getBody());
    }

    public function testTooManyCodesTriedAreRefused(): void
    {
        $this->post();
        for ($i = 0; $i < 10; ++$i) {
            $this->code(['code' => '123456']);
        }

        $limited = $this->code(['code' => '123456']);

        self::assertSame(429, $limited->getStatusCode());
        self::assertStringContainsString('You have tried a code too many times.', (string) $limited->getBody());
    }

    public function testWithoutAPendingCodeTheCodePageSendsPeopleBack(): void
    {
        self::assertSame('/register', $this->code()->getHeaderLine('Location'));

        $this->auth->database->settings()->set(SignUp::SETTING, '0');
        self::assertSame('/login', $this->code()->getHeaderLine('Location'));

        CodeSession::startPending($this->session, VerificationPurpose::EmailVerify, 'nia@example.org');
        $html = (string) $this->code()->getBody();
        self::assertStringNotContainsString('Use another email address', $html, 'sign-up closed: only the code');
        self::assertStringContainsString('<a href="/login">Back to sign in</a>', $html);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body = [], string $uri = '/register', ?EmailPolicy $policy = null, ?RateLimiters $limiters = null, ?Site $site = null): ResponseInterface
    {
        $body += [
            'first_name' => 'Nia',
            'last_name' => 'Lind',
            'display_name' => '',
            'email' => ' Nia@Example.org ',
            'timezone' => 'Asia/Tokyo',
            'password' => AuthFixture::PASSWORD,
            'website' => '',
        ];

        return $this->handler($site, $policy, $limiters)->handle($this->auth->request('POST', 'https://demo.example' . $uri, $body, session: $this->session));
    }

    private function register(string $uri = '/register'): ResponseInterface
    {
        return $this->handler()->handle($this->auth->request('GET', 'https://demo.example' . $uri, session: $this->session));
    }

    private function handler(?Site $site = null, ?EmailPolicy $policy = null, ?RateLimiters $limiters = null): RegisterHandler
    {
        $auth = $this->auth;

        return new RegisterHandler(
            $auth->pages(),
            $auth->validator(),
            $auth->selfRegistration(),
            $auth->passwords(),
            $policy ?? new AnyEmailPolicy(),
            $limiters ?? $auth->limiters,
            $auth->timing(),
            $site ?? $auth->site,
        );
    }

    /**
     * @param array<string, string> $body
     */
    private function code(array $body = []): ResponseInterface
    {
        $auth = $this->auth;

        return new RegisterCodeHandler($auth->pages(), $auth->validator(), $auth->selfRegistration(), $auth->timing())
            ->handle($auth->request($body === [] ? 'GET' : 'POST', 'https://demo.example/register/code', $body, session: $this->session));
    }
}
