<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\People;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The roles screens through the whole kernel (X-4): only people who manage roles reach them, the server refuses what
 * the form doesn't offer whatever is posted, nobody takes away their own way to manage roles, and every change is in
 * the audit log.
 */
#[CoversNothing]
final class RoleAdminFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('roles');
        $this->user('ada@example.org', 'admin');
        $this->user('bea@example.org', 'editor');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testOnlyPeopleWhoManageRolesReachTheScreens(): void
    {
        $editor = $this->signedIn('bea@example.org');
        self::assertSame(403, $editor->request('GET', '/admin/roles')->getStatusCode());
        self::assertSame(403, $editor->request('GET', '/admin/roles/editor')->getStatusCode());

        $admin = $this->signedIn('ada@example.org');
        self::assertSame(200, $admin->request('GET', '/admin/roles')->getStatusCode());
        self::assertSame(404, $admin->request('GET', '/admin/roles/ghost')->getStatusCode());
        self::assertSame(200, $admin->request('GET', '/admin/roles/new')->getStatusCode());
    }

    public function testForgedPostsAreRefused(): void
    {
        $admin = $this->signedIn('ada@example.org');

        $forged = $admin->submit('/admin/roles/member', ['label' => 'Member', 'area_page' => ['page.create']]);
        self::assertSame(422, $forged->getStatusCode());
        self::assertSame([], $this->roles()->find('member')?->permissions);

        $forged = $admin->submit('/admin/roles/new', ['label' => 'Sneaky', 'area_page' => ['acme.everything']]);
        self::assertSame(422, $forged->getStatusCode());
        self::assertNull($this->roles()->find('sneaky'));

        $forged = $admin->submit('/admin/roles/admin', ['label' => 'Admin'], '/admin/roles/admin/delete');
        self::assertSame(422, $forged->getStatusCode());
        self::assertNotNull($this->roles()->find('admin'));
    }

    public function testNobodyTakesAwayTheirOwnWayToManageRoles(): void
    {
        $admin = $this->signedIn('ada@example.org');
        self::assertSame(303, $admin->submit('/admin/roles/new', ['label' => 'Role keeper', 'area_role' => ['role.manage']])->getStatusCode());
        $this->user('cleo@example.org', 'role_keeper');
        $cleo = $this->signedIn('cleo@example.org');

        $refused = $cleo->submit('/admin/roles/role_keeper', ['label' => 'Role keeper']);

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('You manage roles through the “Role keeper” role', (string) $refused->getBody());
        self::assertSame(200, $cleo->request('GET', '/admin/roles')->getStatusCode());

        self::assertSame(303, $admin->submit('/admin/roles/role_keeper', ['label' => 'Role keeper'])->getStatusCode());
        self::assertSame(403, $cleo->request('GET', '/admin/roles')->getStatusCode());
        $actions = array_map(static fn ($entry): string => $entry->action, Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(entityType: 'role'), 10));
        self::assertSame(['role.permissions_changed', 'role.created'], $actions);
    }

    private function roles(): Roles
    {
        return Services::get($this->site->container(), Roles::class);
    }

    private function signedIn(string $email): Browser
    {
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $browser;
    }

    private function user(string $email, string $role): void
    {
        $container = $this->site->container();
        $user = new User($email, ucfirst((string) strstr($email, '@', true)), 'Tester', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $users = Services::get($container, UserRepository::class);
        $users->save($user);
        Services::get($container, AvatarService::class)->generate($user);
        $users->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
    }
}
