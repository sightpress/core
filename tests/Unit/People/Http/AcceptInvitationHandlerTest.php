<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\People\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Auth\PasswordRules;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\People\AnyEmailPolicy;
use SightPress\Core\People\EmailPolicy;
use SightPress\Core\People\Http\AcceptInvitationHandler;
use SightPress\Core\People\Http\InvitationCodeHandler;
use SightPress\Core\People\Http\InvitationPages;
use SightPress\Core\People\Invitations;
use SightPress\Core\Rbac\RoleGrantPolicy;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\User\AccountRegistrar;
use SightPress\Core\User\AvatarKind;

#[CoversClass(AcceptInvitationHandler::class)]
#[CoversClass(InvitationCodeHandler::class)]
#[CoversClass(InvitationPages::class)]
#[UsesNamespace('SightPress\Core')]
final class AcceptInvitationHandlerTest extends TestCase
{
    private const string PASSWORD = 'A long and lovely password, 2!';

    private AuthFixture $auth;

    private Session $session;

    private Invitations $invitations;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->session = new Session();
        $this->invitations = new Invitations($this->auth->database->connection, $this->auth->clock, $this->auth->codes());
    }

    public function testTheCodePageAsksForTheAddressAndTheCode(): void
    {
        $response = $this->enter(null);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<title>Accept an invitation - Demo</title>', $html);
        self::assertStringContainsString('action="/login/invitation"', $html);
        self::assertStringContainsString('<label for="invitation-code-email">Email address</label>', $html);
        self::assertStringContainsString('<label for="invitation-code-code">6-digit code</label>', $html);
        self::assertStringContainsString('autocomplete="one-time-code" inputmode="numeric"', $html);
        self::assertStringContainsString('We never send links.', $html);
        self::assertStringNotContainsString('name="resend"', $html);
    }

    public function testAWrongUsedOrExpiredCodeGetsTheSameAnswer(): void
    {
        [, $code] = $this->invitations->create('nia@example.org', 'contributor', null, null, null);

        self::assertStringContainsString('Enter the 6 digits of the code', (string) $this->enter('nia@example.org', '12')->getBody());
        $wrongAddress = $this->enter('max@example.org', $code->code);
        self::assertSame(422, $wrongAddress->getStatusCode());
        self::assertStringContainsString(InvitationCodeHandler::WRONG, (string) $wrongAddress->getBody());

        $this->auth->clock->advance(Invitations::TTL);
        self::assertStringContainsString(InvitationCodeHandler::WRONG, (string) $this->enter('nia@example.org', $code->code)->getBody());
        self::assertSame('/login/invitation', $this->handle()->getHeaderLine('Location'), 'no checked code, no form');
    }

    public function testTooManyTriesAreExplained(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->enter('nia@example.org', '123456');
        }

        $limited = $this->enter('nia@example.org', '123456');

        self::assertSame(429, $limited->getStatusCode());
        self::assertMatchesRegularExpression('/You have tried a code too many times\. Wait 1[56] minutes and try again\./', (string) $limited->getBody());
    }

    public function testACheckedCodeOpensTheFormForAnHourOnly(): void
    {
        [, $code] = $this->invitations->create('nia@example.org', 'contributor', null, null, null);
        $this->enter('nia@example.org', $code->code);

        $this->auth->clock->advance(AcceptInvitationHandler::VERIFIED_FOR + 1);

        self::assertSame('/login/invitation', $this->handle()->getHeaderLine('Location'));
        self::assertSame('/login/invitation', $this->handle()->getHeaderLine('Location'), 'and it is forgotten');
    }

    public function testTheFormIsPrefilledWithWhatTheInviterKnewAndTheSitesTimeZone(): void
    {
        [, $code] = $this->invitations->create('nia@example.org', 'contributor', 'Nia', null, null);
        $entered = $this->enter('nia@example.org', $code->readable());
        self::assertSame(303, $entered->getStatusCode());
        self::assertSame('/login/invitation/account', $entered->getHeaderLine('Location'));

        $response = $this->handle();
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('<title>Join Demo - Demo</title>', $html);
        self::assertStringContainsString('You have been invited to join as contributor. Your account will use nia@example.org.', $html);
        self::assertStringContainsString('value="Nia" name="first_name"', $html);
        self::assertStringContainsString('<option value="Europe/London" selected>London</option>', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertMatchesRegularExpression('#<script src="/assets/js/timezone\.js[^"]*" data-select="accept-timezone" defer></script>#', $html);
        self::assertStringContainsString('action="/login/invitation/account"', $html);
    }

    public function testCreatesTheAccountWithItsRoleAndAvatarAndAsksToSignIn(): void
    {
        [$invitation, $code] = $this->invitations->create('nia@example.org', 'contributor', 'Nia', null, 1);
        $this->enter('nia@example.org', $code->code);

        $response = $this->handle([
            'first_name' => 'Nia',
            'last_name' => 'Lind',
            'display_name' => '',
            'timezone' => 'Asia/Tokyo',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertSame(['Your account is ready. Sign in with nia@example.org and your new password.'], Flash::take($this->session));
        $user = $this->auth->users->findByEmail('nia@example.org');
        self::assertNotNull($user);
        self::assertTrue($user->isActive());
        self::assertSame(['Nia', 'Lind', null, 'Asia/Tokyo'], [$user->firstName(), $user->lastName(), $user->displayName(), $user->timezone()]);
        self::assertTrue($this->auth->hasher->verify(self::PASSWORD, $user->passwordHash()));
        self::assertSame(AvatarKind::Generated, $user->avatarKind());
        self::assertSame(['contributor'], array_map(static fn ($r) => $r->name(), $this->auth->database->roles()->rolesOf((int) $user->id())));
        self::assertNull($this->invitations->findOpen($invitation->id), 'an invitation works once');
        self::assertSame('/login/invitation', $this->handle()->getHeaderLine('Location'));
        self::assertSame(1, $invitation->invitedBy);
    }

    public function testExplainsEveryProblemAndKeepsThePasswordOutOfThePage(): void
    {
        [, $code] = $this->invitations->create('nia@example.org', 'contributor', null, null, null);
        $this->enter('nia@example.org', $code->code);

        $short = $this->handle(['first_name' => '', 'last_name' => 'Lind', 'timezone' => 'Mars/Olympus', 'password' => 'short']);
        $html = (string) $short->getBody();
        self::assertSame(422, $short->getStatusCode());
        self::assertStringContainsString('<title>Error: Join Demo - Demo</title>', $html);
        self::assertStringContainsString('<a href="#accept-first-name">Enter first name</a>', $html);
        self::assertStringContainsString('Select one of the options for “Time zone”', $html);
        self::assertStringContainsString('<a href="#accept-password">Your password must be at least 12 characters long.</a>', $html);
        self::assertStringNotContainsString('value="short"', $html);
        self::assertStringNotContainsString('timezone.js', $html, 'the person\'s own choice is kept after an error');

        $email = (string) $this->handle(['first_name' => 'Nia', 'last_name' => 'Lind', 'timezone' => 'UTC', 'password' => 'nia@example.org'])->getBody();
        self::assertStringContainsString('Your password must not be your email address.', $email);

        $empty = (string) $this->handle(['first_name' => 'Nia', 'last_name' => 'Lind', 'timezone' => 'UTC', 'password' => ''])->getBody();
        self::assertStringContainsString('<a href="#accept-password">Enter password</a>', $empty);
        self::assertNull($this->auth->users->findByEmail('nia@example.org'));
    }

    public function testAnAddressThatHasAnAccountByNowOrIsRefusedByThePolicyIsExplained(): void
    {
        [$invitation, $code] = $this->invitations->create('ada@example.org', 'contributor', null, null, null);
        $this->enter('ada@example.org', $code->code);
        $this->auth->user('ada@example.org');

        $taken = $this->handle(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'timezone' => 'UTC', 'password' => self::PASSWORD]);
        self::assertSame(409, $taken->getStatusCode());
        self::assertStringContainsString('There is already an account for ada@example.org.', (string) $taken->getBody());
        self::assertNotNull($this->invitations->findOpen($invitation->id));

        $policy = self::createStub(EmailPolicy::class);
        $policy->method('problem')->willReturn('Only addresses at example.com can join.');
        $refused = $this->handle(policy: $policy);
        self::assertSame(403, $refused->getStatusCode());
        self::assertStringContainsString('<p>Only addresses at example.com can join.</p>', (string) $refused->getBody());
    }

    /**
     * @param array<string, string> $body
     */
    private function handle(array $body = [], ?EmailPolicy $policy = null): ResponseInterface
    {
        $auth = $this->auth;
        $handler = new AcceptInvitationHandler(
            new InvitationPages($auth->templates, $auth->site),
            $auth->http,
            $auth->validator(),
            $this->invitations,
            new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock),
            new RoleGrantPolicy($auth->database->roles(), new RecordingEvents()),
            $auth->passwords(),
            $policy ?? new AnyEmailPolicy(),
            $auth->site,
            $auth->clock,
        );

        return $handler->handle($auth->request($body === [] ? 'GET' : 'POST', 'https://demo.example/login/invitation/account', $body, session: $this->session));
    }

    /**
     * Types the address and the code on "Accept an invitation" (or just opens it, without an address).
     */
    private function enter(?string $email, string $code = ''): ResponseInterface
    {
        $auth = $this->auth;
        $handler = new InvitationCodeHandler(new InvitationPages($auth->templates, $auth->site), $auth->validator(), $this->invitations, $auth->clock, $auth->http);
        $body = $email === null ? [] : ['email' => $email, 'code' => $code];

        return $handler->handle($auth->request($email === null ? 'GET' : 'POST', 'https://demo.example/login/invitation', $body, session: $this->session));
    }
}
