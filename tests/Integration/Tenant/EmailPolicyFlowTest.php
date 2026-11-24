<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Tenant;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * `email_policy: ['domains' => …]` through the whole kernel (X-5): inviting, signing up and changing one's address
 * are refused for an address elsewhere, with a message naming the allowed domains; an account made before the policy
 * keeps signing in.
 */
#[CoversNothing]
final class EmailPolicyFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private const string REFUSED = 'Only email addresses at example.org and xn--bcher-kva.example can join this site. Use an address there.';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('email-policy', tenant: ['email_policy' => ['domains' => ['Example.org', 'bücher.example']]]);
        $this->user('ada@example.org', 'admin');
        $this->user('old@elsewhere.test', 'editor');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testEveryWayInRefusesAnAddressElsewhere(): void
    {
        $admin = $this->signedIn('ada@example.org');
        $invite = $admin->submit('/admin/users/invite', ['email' => 'nia@evil-example.org', 'role' => 'member']);
        self::assertSame(422, $invite->getStatusCode());
        self::assertStringContainsString(self::REFUSED, (string) $invite->getBody());
        self::assertSame(303, $admin->submit('/admin/users/invite', ['email' => 'nia@EXAMPLE.org', 'role' => 'member'])->getStatusCode());

        Services::get($this->site->container(), SiteSettings::class)->set('people.registration', '1');
        $guest = new Browser($this->site->kernel, 'http://example.org');
        $signUp = $guest->submit('/register', ['first_name' => 'Zoë', 'last_name' => 'Lind', 'display_name' => '', 'email' => 'zoe@example.org.evil.test', 'timezone' => 'UTC', 'password' => self::PASSWORD, 'website' => '']);
        self::assertSame(422, $signUp->getStatusCode());
        self::assertStringContainsString(self::REFUSED, (string) $signUp->getBody());

        $change = $admin->submit('/account/email', ['new_email' => 'ada@mail.example.org', 'current_password' => self::PASSWORD]);
        self::assertSame(422, $change->getStatusCode());
        self::assertStringContainsString(self::REFUSED, (string) $change->getBody());
    }

    public function testAnAccountMadeBeforeThePolicyKeepsSigningIn(): void
    {
        self::assertSame(200, $this->signedIn('old@elsewhere.test')->request('GET', '/admin')->getStatusCode());
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
        $user = new User($email, 'Someone', 'Tester', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $users = Services::get($container, UserRepository::class);
        $users->save($user);
        Services::get($container, AvatarService::class)->generate($user);
        $users->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
    }
}
