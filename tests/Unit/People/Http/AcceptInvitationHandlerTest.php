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
use SightPress\Core\People\Invitations;
use SightPress\Core\Rbac\RoleGrantPolicy;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\User\AccountRegistrar;
use SightPress\Core\User\AvatarKind;

#[CoversClass(AcceptInvitationHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class AcceptInvitationHandlerTest extends TestCase
{
    private const string PASSWORD = 'a long and lovely password';

    private AuthFixture $auth;

    private Session $session;

    private Invitations $invitations;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->session = new Session();
        $this->invitations = new Invitations($this->auth->database->connection, $this->auth->clock);
    }

    public function testAnUnknownUsedOrExpiredLinkSaysWhatToDo(): void
    {
        [, $token] = $this->invitations->create('nia@example.org', 'contributor', null, null, null);
        $this->auth->clock->advance(Invitations::TTL);

        foreach ([$token, 'nonsense', null] as $attempt) {
            $response = $this->handle($attempt);
            $html = (string) $response->getBody();

            self::assertSame(410, $response->getStatusCode());
            self::assertStringContainsString('<h1>This invitation no longer works</h1>', $html);
            self::assertStringContainsString('Ask the person who invited you to send a new invitation.', $html);
            self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        }
    }

    public function testTheFormIsPrefilledWithWhatTheInviterKnewAndTheSitesTimeZone(): void
    {
        [, $token] = $this->invitations->create('nia@example.org', 'contributor', 'Nia', null, null);

        $response = $this->handle($token);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('<title>Join Demo - Demo</title>', $html);
        self::assertStringContainsString('You have been invited to join as contributor. Your account will use nia@example.org.', $html);
        self::assertStringContainsString('value="Nia" name="first_name"', $html);
        self::assertStringContainsString('<option value="Europe/London" selected>London</option>', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertMatchesRegularExpression('#<script src="/assets/js/timezone\.js[^"]*" data-select="accept-timezone" defer></script>#', $html);
        self::assertStringContainsString('action="/invite/' . $token . '"', $html);
    }

    public function testCreatesTheAccountWithItsRoleAndAvatarAndAsksToSignIn(): void
    {
        [$invitation, $token] = $this->invitations->create('nia@example.org', 'contributor', 'Nia', null, 1);

        $response = $this->handle($token, [
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
        self::assertNull($this->invitations->find($token), 'the link works once');
        self::assertSame(410, $this->handle($token)->getStatusCode());
        self::assertSame(1, $invitation->invitedBy);
    }

    public function testExplainsEveryProblemAndKeepsThePasswordOutOfThePage(): void
    {
        [, $token] = $this->invitations->create('nia@example.org', 'contributor', null, null, null);

        $short = $this->handle($token, ['first_name' => '', 'last_name' => 'Lind', 'timezone' => 'Mars/Olympus', 'password' => 'short']);
        $html = (string) $short->getBody();
        self::assertSame(422, $short->getStatusCode());
        self::assertStringContainsString('<title>Error: Join Demo - Demo</title>', $html);
        self::assertStringContainsString('<a href="#accept-first-name">Enter first name</a>', $html);
        self::assertStringContainsString('Select one of the options for “Time zone”', $html);
        self::assertStringContainsString('<a href="#accept-password">Your password must be at least 12 characters long.</a>', $html);
        self::assertStringNotContainsString('value="short"', $html);
        self::assertStringNotContainsString('timezone.js', $html, 'the person\'s own choice is kept after an error');

        $email = (string) $this->handle($token, ['first_name' => 'Nia', 'last_name' => 'Lind', 'timezone' => 'UTC', 'password' => 'nia@example.org'])->getBody();
        self::assertStringContainsString('Your password must not be your email address.', $email);

        $empty = (string) $this->handle($token, ['first_name' => 'Nia', 'last_name' => 'Lind', 'timezone' => 'UTC', 'password' => ''])->getBody();
        self::assertStringContainsString('<a href="#accept-password">Enter password</a>', $empty);
        self::assertNull($this->auth->users->findByEmail('nia@example.org'));
    }

    public function testAnAddressThatHasAnAccountByNowOrIsRefusedByThePolicyIsExplained(): void
    {
        [, $token] = $this->invitations->create('ada@example.org', 'contributor', null, null, null);
        $this->auth->user('ada@example.org');

        $taken = $this->handle($token, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'timezone' => 'UTC', 'password' => self::PASSWORD]);
        self::assertSame(409, $taken->getStatusCode());
        self::assertStringContainsString('There is already an account for ada@example.org.', (string) $taken->getBody());
        self::assertNotNull($this->invitations->find($token));

        $policy = self::createStub(EmailPolicy::class);
        $policy->method('problem')->willReturn('Only addresses at example.com can join.');
        $refused = $this->handle($token, policy: $policy);
        self::assertSame(403, $refused->getStatusCode());
        self::assertStringContainsString('<p>Only addresses at example.com can join.</p>', (string) $refused->getBody());
    }

    /**
     * @param array<string, string> $body
     */
    private function handle(?string $token, array $body = [], ?EmailPolicy $policy = null): ResponseInterface
    {
        $auth = $this->auth;
        $handler = new AcceptInvitationHandler(
            $auth->templates,
            $auth->http,
            $auth->validator(),
            $this->invitations,
            new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock),
            new RoleGrantPolicy($auth->database->roles()),
            new PasswordRules($auth->hasher),
            $policy ?? new AnyEmailPolicy(),
            $auth->site,
        );
        $request = $auth->request($body === [] ? 'GET' : 'POST', 'https://demo.example/invite/' . $token, $body, session: $this->session)
            ->withAttribute(AcceptInvitationHandler::TOKEN_ATTRIBUTE, $token);

        return $handler->handle($request);
    }
}
