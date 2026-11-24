<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\People\Http;

use SightPress\Core\Tests\Support\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Auth\Contract\EmailPolicy;
use SightPress\Core\People\Http\InviteHandler;
use SightPress\Core\People\InvitationMailer;
use SightPress\Core\People\Invitations;
use SightPress\Core\Rbac\Contract\RoleChoice;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Rbac\RoleGrantPolicy;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(InviteHandler::class)]
#[CoversClass(InvitationMailer::class)]
#[UsesNamespace('SightPress\Core')]
final class InviteHandlerTest extends TestCase
{
    private AdminFixture $admin;

    private Session $session;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $this->session = new Session();
    }

    public function testPeopleWhoMayNotInviteAreRefused(): void
    {
        $cleo = $this->admin->auth->user('cleo@example.org', 'contributor');

        $this->expectException(HttpForbiddenException::class);
        $this->handle($cleo);
    }

    public function testEditorsSeeOnlyTheContributorRoleChosenForThem(): void
    {
        $html = (string) $this->handle($this->admin->auth->user())->getBody();

        self::assertStringContainsString('<title>Invite someone - Admin - Demo</title>', $html);
        self::assertStringContainsString('<legend>Role</legend>', $html);
        self::assertStringContainsString('value="contributor" checked', $html);
        self::assertStringNotContainsString('value="editor"', $html);
        self::assertStringContainsString('<a href="/admin/users/invite" aria-current="page">Invite someone</a>', $html);
    }

    public function testAdminsChooseAnyRole(): void
    {
        $html = (string) $this->handle($this->admin->auth->user('ada@example.org', 'admin'))->getBody();

        foreach (['admin', 'editor', 'contributor', 'member'] as $role) {
            self::assertStringContainsString('value="' . $role . '"', $html);
        }
        self::assertStringNotContainsString(' checked', $html, 'nothing is chosen for them');
    }

    public function testEditorsCantGiveAnyOtherRoleWhateverTheySend(): void
    {
        $response = $this->handle($this->admin->auth->user(), ['email' => 'nia@example.org', 'role' => 'admin']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Select one of the options for “Role”', (string) $response->getBody());
        self::assertSame([], Text::of($this->invitations()->open()));
        self::assertSame([], Text::of($this->admin->auth->sent));
    }

    public function testSendsTheInvitationAndSaysSo(): void
    {
        $bea = $this->admin->auth->user('bea@example.org');

        $response = $this->handle($bea, ['email' => ' Nia@Example.org ', 'role' => 'contributor', 'first_name' => 'Nia', 'last_name' => '']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/users/invite', Text::of($response->getHeaderLine('Location')));
        self::assertSame(['We have sent an invitation to nia@example.org to join as contributor. Its code works for 7 days.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        $open = $this->invitations()->open();
        self::assertCount(1, $open);
        self::assertSame(['nia@example.org', 'contributor', 'Nia', null, $bea->id()], Text::of([$open[0]->email, $open[0]->role, $open[0]->firstName, $open[0]->lastName, $open[0]->invitedBy]));

        self::assertCount(1, $this->admin->auth->sent);
        $email = $this->admin->auth->sent[0];
        self::assertSame('nia@example.org', Text::of($email->getTo()[0]->getAddress()));
        self::assertSame('Ada invited you to Demo', Text::of($email->getSubject()));
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Hello Nia,', $text);
        self::assertStringContainsString('Ada has invited you to join Demo as contributor.', $text);
        self::assertStringContainsString('To accept, go to the sign-in page of Demo, choose “Accept an invitation”, and type your email address and this invitation code:', $text);
        self::assertStringContainsString('The invitation works until 21 Nov 2026, 12:00:00 (Europe/London time).', $text);
        self::assertStringContainsString('The code works for 7 days, and only once.', $text);
        self::assertStringContainsString('We will never send you a link.', $text);
        $code = AuthFixture::codeIn($email);
        self::assertStringNotContainsString($code, (string) $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString(substr($code, 0, 3) . ' ' . substr($code, 3), $html);
        self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $html);
        foreach ([$text, $html] as $body) {
            self::assertDoesNotMatchRegularExpression('#https?://|<a\b|www\.|demo\.example|<img\b#i', $body, 'no link, no web address, no image');
        }
        self::assertSame('nia@example.org', Text::of($this->invitations()->verify('nia@example.org', $code, '192.0.2.1')?->email));
    }

    public function testAnAddressWithAnAccountOrRefusedByThePolicyIsExplained(): void
    {
        $ada = $this->admin->auth->user('ada@example.org', 'admin');
        $this->admin->auth->user('max@example.org', 'member');

        $taken = $this->handle($ada, ['email' => 'MAX@example.org', 'role' => 'member']);
        self::assertSame(422, $taken->getStatusCode());
        self::assertStringContainsString('<a href="#invite-email">There is already an account for max@example.org.</a>', (string) $taken->getBody());

        $policy = self::createStub(EmailPolicy::class);
        $policy->method('problem')->willReturn(new \SightPress\Core\Template\Message('Only addresses at example.org can join.'));
        $refused = $this->handle($ada, ['email' => 'nia@elsewhere.example', 'role' => 'member'], policy: $policy);
        self::assertStringContainsString('Only addresses at example.org can join.', (string) $refused->getBody());
        self::assertSame([], Text::of($this->invitations()->open()));
    }

    public function testARoleThatCanNoLongerBeGivenIsRefused(): void
    {
        $grants = self::createStub(RoleGrants::class);
        $grants->method('grantableBy')->willReturn([new RoleChoice('editor', 'Editor', 'Edits.')]);
        $grants->method('canGrant')->willReturn(false);

        $response = $this->handle($this->admin->auth->user(), ['email' => 'nia@example.org', 'role' => 'editor'], $grants);

        self::assertStringContainsString('Select a role you are allowed to give.', (string) $response->getBody());
    }

    public function testAnInviterWithoutAnAccountIsSomeone(): void
    {
        $this->handle(null, ['email' => 'nia@example.org', 'role' => 'contributor'], permissions: ['admin.access', 'user.invite']);

        self::assertSame('Someone invited you to Demo', Text::of($this->admin->auth->sent[0]->getSubject()));
        self::assertStringContainsString("Hello,\n", (string) $this->admin->auth->sent[0]->getTextBody());
    }

    /**
     * @param array<string, string> $body
     * @param ?list<string>         $permissions for a request without a user
     */
    private function handle(?User $user, array $body = [], ?RoleGrants $grants = null, ?EmailPolicy $policy = null, ?array $permissions = null): ResponseInterface
    {
        $auth = $this->admin->auth;
        $handler = new InviteHandler(
            $this->admin->shell(),
            $auth->validator(),
            $grants ?? new RoleGrantPolicy($auth->database->roles(), new RecordingEvents()),
            $auth->users,
            $this->invitations(),
            new InvitationMailer($auth, $auth->templates, $auth->site, $auth->translations),
            $policy ?? new \SightPress\Core\Auth\AnyEmailPolicy(),
        );
        $request = $this->admin->request($user, $body === [] ? 'GET' : 'POST', InviteHandler::PATH, $body, $this->session);
        if ($permissions !== null) {
            $request = $request->withAttribute(\SightPress\Core\Rbac\Contract\Actor::class, new \SightPress\Core\Rbac\Contract\Actor(999, $permissions));
        }

        return $handler->handle($request);
    }

    private function invitations(): Invitations
    {
        return new Invitations($this->admin->auth->database->connection, $this->admin->auth->clock, $this->admin->auth->codes());
    }
}
