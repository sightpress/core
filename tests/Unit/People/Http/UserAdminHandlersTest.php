<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\People\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\People\Http\UserHandler;
use SightPress\Core\People\Http\UsersHandler;
use SightPress\Core\People\Http\UserStatusHandler;
use SightPress\Core\People\Invitations;
use SightPress\Core\Rbac\Contract\RoleChoice;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Rbac\RoleGrantPolicy;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\User\AccountAdministration;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(UsersHandler::class)]
#[CoversClass(UserHandler::class)]
#[CoversClass(UserStatusHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class UserAdminHandlersTest extends TestCase
{
    private AdminFixture $admin;

    private Session $session;

    private User $ada;

    private User $bea;

    private User $cleo;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $this->session = new Session();
        $this->ada = $this->person('ada@example.org', 'Ada', 'Lovelace', 'admin');
        $this->bea = $this->person('bea@example.org', 'Bea', 'Okafor', 'editor');
        $this->cleo = $this->person('cleo@example.org', 'Cleo', 'Martins', 'contributor');
    }

    public function testOnlyPeopleWhoManageUsersSeeTheList(): void
    {
        $this->expectException(HttpForbiddenException::class);
        $this->list($this->bea);
    }

    public function testTheListIsADataTableSortedByNameWithAnAnnouncedSortOrder(): void
    {
        $max = $this->person('max@example.org', 'Max', 'Müller', null);
        $max->deactivate(new DateTimeImmutable());
        $this->admin->auth->users->save($max);

        $html = (string) $this->list($this->ada)->getBody();

        self::assertStringContainsString('<title>People - Admin - Demo</title>', $html);
        self::assertStringContainsString('<caption id="people-caption">', $html);
        self::assertStringContainsString('Everyone with an account, sorted by name, A to Z', $html);
        self::assertMatchesRegularExpression('#<th scope="col" aria-sort="ascending">\s*<a href="\?sort=name&amp;order=descending">Name<span aria-hidden="true"> ▲</span></a>#', $html);
        self::assertStringContainsString('<a href="?sort=email&amp;order=ascending">Email address</a>', $html);
        self::assertSame(['Ada', 'Bea', 'Cleo', 'Max'], self::names($html));
        self::assertMatchesRegularExpression('#<img class="avatar" src="/avatars/[0-9a-f]{32}\.svg" alt="" width="32" height="32">#', $html);
        self::assertStringContainsString('<td>No role</td>', $html);
        self::assertStringContainsString('<td>Deactivated</td>', $html);
        self::assertStringContainsString('<a class="button" href="/admin/users/invite">Invite someone</a>', $html);
        self::assertStringContainsString('There are no open invitations.', $html);
        self::assertStringContainsString('<a href="/admin/users" aria-current="page">People</a>', $html);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('sortings')]
    public function testEachColumnSortsBothWays(string $query, string $caption, array $expected): void
    {
        $html = (string) $this->list($this->ada, $query)->getBody();

        self::assertStringContainsString($caption, $html);
        self::assertSame($expected, self::names($html));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function sortings(): iterable
    {
        yield 'name, Z to A' => ['?sort=name&order=descending', 'sorted by name, Z to A', ['Cleo', 'Bea', 'Ada']];
        yield 'full name, Z to A' => ['?sort=full_name&order=descending', 'sorted by full name, Z to A', ['Cleo', 'Bea', 'Ada']];
        yield 'email, Z to A' => ['?sort=email&order=descending', 'sorted by email address, Z to A', ['Cleo', 'Bea', 'Ada']];
        yield 'role' => ['?sort=role', 'sorted by role, A to Z', ['Ada', 'Cleo', 'Bea']];
        yield 'status, ties by id' => ['?sort=status', 'sorted by status, A to Z', ['Ada', 'Bea', 'Cleo']];
        yield 'unknown column' => ['?sort=password', 'sorted by name, A to Z', ['Ada', 'Bea', 'Cleo']];
    }

    public function testOpenInvitationsAreListedWithWhoSentThem(): void
    {
        $invitations = new Invitations($this->admin->auth->database->connection, $this->admin->auth->clock);
        $invitations->create('nia@example.org', 'editor', null, null, $this->ada->id());
        $invitations->create('olu@example.org', 'retired', null, null, null);
        $invitations->create('pat@example.org', 'member', null, null, 999);

        $html = (string) $this->list($this->ada)->getBody();

        self::assertStringContainsString('<caption id="people-invitations-caption">Open invitations, newest first</caption>', $html);
        self::assertMatchesRegularExpression('#<th scope="row">nia@example\.org</th>\s*<td>Editor</td>\s*<td>Ada</td>\s*<td><time datetime="2026-11-21T12:00:00Z">#', $html);
        self::assertMatchesRegularExpression('#<th scope="row">olu@example\.org</th>\s*<td>retired</td>\s*<td>Someone</td>#', $html);
        self::assertMatchesRegularExpression('#<th scope="row">pat@example\.org</th>\s*<td>Member</td>\s*<td>Someone</td>#', $html);
    }

    public function testAPersonsPageShowsTheirDetailsRoleAndStatus(): void
    {
        $html = (string) $this->user($this->ada, $this->bea)->getBody();

        self::assertStringContainsString('<title>Bea - Admin - Demo</title>', $html);
        self::assertMatchesRegularExpression('#<h1 class="people-heading"><img class="avatar" src="/avatars/[0-9a-f]{32}\.svg" alt="" width="48" height="48"> Bea</h1>#', $html);
        self::assertStringContainsString('<dt>Full name</dt><dd>Bea Okafor</dd>', $html);
        self::assertStringContainsString('<dt>Role</dt><dd>Editor</dd>', $html);
        self::assertStringContainsString('<dt>Status</dt><dd>Active</dd>', $html);
        self::assertStringContainsString('<legend>Role</legend>', $html);
        self::assertStringContainsString('value="editor" checked', $html);
        self::assertStringContainsString('<form action="/admin/users/' . $this->bea->id() . '/status" method="post">', $html);
        self::assertStringContainsString('Deactivate Bea</button>', $html);
    }

    public function testNobodyChangesTheirOwnRoleOrStatus(): void
    {
        $html = (string) $this->user($this->ada, $this->ada, ['role' => 'member'])->getBody();

        self::assertStringContainsString('This is your own account.', $html);
        self::assertStringNotContainsString('<legend>Role</legend>', $html);
        self::assertSame('admin', $this->roles()->roleOf((int) $this->ada->id())?->name);

        $response = $this->changeStatus($this->ada, $this->ada);
        self::assertSame(['You can’t deactivate your own account.'], Flash::take($this->session));
        self::assertSame('/admin/users/' . $this->ada->id(), $response->getHeaderLine('Location'));
        self::assertTrue($this->admin->auth->users->find((int) $this->ada->id())?->isActive());
    }

    public function testChangesSomeonesRoleAndSaysSo(): void
    {
        $response = $this->user($this->ada, $this->bea, ['role' => 'contributor']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/users/' . $this->bea->id(), $response->getHeaderLine('Location'));
        self::assertSame(['Bea’s role is now Contributor.'], Flash::take($this->session));
        self::assertSame('contributor', $this->roles()->roleOf((int) $this->bea->id())?->name);

        $this->user($this->ada, $this->bea, ['role' => 'contributor']);
        self::assertSame(['Bea already has this role. Nothing was changed.'], Flash::take($this->session));
    }

    public function testAMissingOrRefusedRoleIsExplained(): void
    {
        $missing = $this->user($this->ada, $this->bea, ['role' => '']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('<title>1 error: Bea - Admin - Demo</title>', (string) $missing->getBody());

        $grants = self::createStub(RoleGrants::class);
        $grants->method('grantableBy')->willReturn([new RoleChoice('editor', 'Editor', 'Edits.')]);
        $grants->method('canGrant')->willReturn(false);
        $refused = $this->user($this->ada, $this->bea, ['role' => 'editor'], $grants);
        self::assertStringContainsString('Select a role you are allowed to give.', (string) $refused->getBody());
    }

    public function testSomeoneWithoutARoleHasNoneChosen(): void
    {
        $max = $this->person('max@example.org', 'Max', 'Müller', null);

        $html = (string) $this->user($this->ada, $max)->getBody();

        self::assertStringContainsString('<dt>Role</dt><dd>No role</dd>', $html);
        self::assertStringNotContainsString('checked', $html);
    }

    public function testUnknownPeopleAreNotFound(): void
    {
        foreach (['999', 'abc', null] as $id) {
            try {
                new UserHandler($this->admin->shell(), $this->admin->auth->validator(), $this->roster(), $this->roles())
                    ->handle($this->request($this->ada, 'GET', '/admin/users/x', [], $id));
                self::fail('Found ' . var_export($id, true));
            } catch (HttpNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOnlyPeopleWhoManageUsersOpenAPerson(): void
    {
        $this->expectException(HttpForbiddenException::class);
        $this->changeStatus($this->bea, $this->cleo);
    }

    public function testDeactivatesAndReactivatesSomeone(): void
    {
        $this->changeStatus($this->ada, $this->cleo);
        self::assertSame(['Cleo has been deactivated and can no longer sign in. Their pages stay.'], Flash::take($this->session));
        self::assertFalse($this->admin->auth->users->find((int) $this->cleo->id())?->isActive());

        $html = (string) $this->user($this->ada, $this->cleo)->getBody();
        self::assertStringContainsString('<dt>Status</dt><dd>Deactivated</dd>', $html);
        self::assertStringContainsString('Reactivate Cleo</button>', $html);

        $this->changeStatus($this->ada, $this->cleo);
        self::assertSame(['Cleo has been reactivated and can sign in again.'], Flash::take($this->session));
        self::assertTrue($this->admin->auth->users->find((int) $this->cleo->id())?->isActive());
    }

    private function list(User $actor, string $query = ''): ResponseInterface
    {
        $auth = $this->admin->auth;

        return new UsersHandler(
            $this->admin->shell(),
            $this->roster(),
            $auth->users,
            $this->roles(),
            new Invitations($auth->database->connection, $auth->clock),
            $auth->site,
        )->handle($this->request($actor, 'GET', UsersHandler::PATH . $query));
    }

    /**
     * @param array<string, string> $body
     */
    private function user(User $actor, User $target, array $body = [], ?RoleGrants $grants = null): ResponseInterface
    {
        return new UserHandler($this->admin->shell(), $this->admin->auth->validator(), $this->roster(), $grants ?? $this->roles())
            ->handle($this->request($actor, $body === [] ? 'GET' : 'POST', UserHandler::path((int) $target->id()), $body, (string) $target->id()));
    }

    private function changeStatus(User $actor, User $target): ResponseInterface
    {
        return new UserStatusHandler($this->admin->shell(), $this->roster())
            ->handle($this->request($actor, 'POST', UserHandler::path((int) $target->id()) . '/status', [], (string) $target->id()));
    }

    /**
     * @param array<string, string> $body
     */
    private function request(User $actor, string $method, string $path, array $body = [], ?string $id = null): ServerRequestInterface
    {
        $request = $this->admin->request($actor, $method, $path, $body, $this->session);

        return $id === null ? $request : $request->withAttribute(UserHandler::ID_ATTRIBUTE, $id);
    }

    private function roster(): AccountAdministration
    {
        return new AccountAdministration($this->admin->auth->users, $this->admin->auth->clock, new RecordingEvents());
    }

    private function roles(): RoleGrantPolicy
    {
        return new RoleGrantPolicy($this->admin->auth->database->roles(), new RecordingEvents());
    }

    private function person(string $email, string $first, string $last, ?string $role): User
    {
        $auth = $this->admin->auth;
        $user = new User($email, $first, $last, 'UTC', new DateTimeImmutable());
        $user->setPasswordHash('$argon2id$hash', new DateTimeImmutable());
        $auth->users->save($user);
        $auth->avatars()->generate($user);
        $auth->users->save($user);
        if ($role !== null) {
            $roles = $auth->database->roles();
            $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
        }

        return $user;
    }

    /**
     * Names in the people table, in order.
     *
     * @return list<string>
     */
    private static function names(string $html): array
    {
        preg_match_all('#<a href="/admin/users/\d+">([^<]+)</a>#', $html, $matches);

        return $matches[1];
    }
}
