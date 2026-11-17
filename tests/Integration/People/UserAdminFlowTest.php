<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\People;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The admin's people screens through the whole kernel (C-3.5): only people who manage users see them, a new role
 * applies from the person's next request, and a deactivated person is signed out straight away.
 */
#[CoversNothing]
final class UserAdminFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('people');
        $this->user('ada@example.org', 'Ada', 'Lovelace', 'admin');
        $this->user('bea@example.org', 'Bea', 'Okafor', 'editor');
        $this->user('cleo@example.org', 'Cleo', 'Martins', 'contributor');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testOnlyPeopleWhoManageUsersSeeThePeopleScreens(): void
    {
        $admin = $this->signedIn('ada@example.org');
        $list = $admin->request('GET', '/admin/users?sort=email&order=descending');
        self::assertSame(200, $list->getStatusCode());
        $html = (string) $list->getBody();
        self::assertStringContainsString('sorted by email address, Z to A', $html);
        self::assertLessThan(strpos($html, '>Ada</a>'), strpos($html, '>Cleo</a>'));

        $editor = $this->signedIn('bea@example.org');
        self::assertSame(403, $editor->request('GET', '/admin/users')->getStatusCode());
        self::assertSame(403, $editor->request('GET', '/admin/users/' . $this->id('cleo@example.org'))->getStatusCode());
        self::assertSame(404, $admin->request('GET', '/admin/users/999')->getStatusCode());
    }

    public function testANewRoleAppliesFromTheNextRequest(): void
    {
        $bea = $this->signedIn('bea@example.org');
        self::assertSame(200, $bea->request('GET', '/admin/users/invite')->getStatusCode());

        $admin = $this->signedIn('ada@example.org');
        $path = '/admin/users/' . $this->id('bea@example.org');
        self::assertSame(303, $admin->submit($path, ['role' => 'member'])->getStatusCode());
        self::assertStringContainsString('Bea’s role is now Member.', (string) $admin->request('GET', $path)->getBody());

        self::assertSame(403, $bea->request('GET', '/admin')->getStatusCode());
    }

    public function testADeactivatedPersonIsSignedOutAndCanBeLetBackIn(): void
    {
        $cleo = $this->signedIn('cleo@example.org');
        self::assertSame(200, $cleo->request('GET', '/admin')->getStatusCode());

        $admin = $this->signedIn('ada@example.org');
        $path = '/admin/users/' . $this->id('cleo@example.org');
        $admin->submit($path, [], $path . '/status');

        self::assertStringStartsWith('/login', $cleo->request('GET', '/admin')->getHeaderLine('Location'));
        $again = new Browser($this->site->kernel, 'http://example.org');
        self::assertNotSame('/admin', $again->submit('/login', ['email' => 'cleo@example.org', 'password' => self::PASSWORD])->getHeaderLine('Location'));

        $admin->submit($path, [], $path . '/status');
        $back = new Browser($this->site->kernel, 'http://example.org');
        self::assertSame('/admin', $back->submit('/login', ['email' => 'cleo@example.org', 'password' => self::PASSWORD])->getHeaderLine('Location'));
    }

    public function testNobodyDeactivatesThemselves(): void
    {
        $admin = $this->signedIn('ada@example.org');
        $path = '/admin/users/' . $this->id('ada@example.org');

        self::assertStringContainsString('This is your own account.', (string) $admin->request('GET', $path)->getBody());
        $admin->submit('/admin/users', [], $path . '/status');

        self::assertSame(200, $admin->request('GET', '/admin')->getStatusCode());
    }

    private function id(string $email): int
    {
        return (int) Services::get($this->site->container(), UserRepository::class)->findByEmail($email)?->id();
    }

    private function signedIn(string $email): Browser
    {
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $browser;
    }

    private function user(string $email, string $first, string $last, string $role): void
    {
        $container = $this->site->container();
        $user = new User($email, $first, $last, 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $users = Services::get($container, UserRepository::class);
        $users->save($user);
        Services::get($container, AvatarService::class)->generate($user);
        $users->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
    }
}
