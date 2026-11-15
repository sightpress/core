<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site;

use DI\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordResetTokens;
use SightPress\Core\Auth\Provisioning\FirstPasswordLink;
use SightPress\Core\Rbac\Provisioning\SeedRoles;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Site\Contract\FirstAdmin;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Core\User\Provisioning\CreateFirstAdmin;
use SightPress\Core\User\UserRepository;

#[CoversClass(FirstAdmin::class)]
#[CoversClass(SiteProvisioning::class)]
#[CoversClass(CreateFirstAdmin::class)]
#[CoversClass(SeedRoles::class)]
#[CoversClass(FirstPasswordLink::class)]
#[UsesNamespace('SightPress\Core')]
final class ProvisioningTest extends TestCase
{
    public function testTheFirstAdminIsNormalisedAndChecked(): void
    {
        $admin = new FirstAdmin(' Ada@Example.ORG ', ' Ada ', ' Lovelace ');

        self::assertSame(['ada@example.org', 'Ada', 'Lovelace'], [$admin->email, $admin->firstName, $admin->lastName]);
    }

    public function testAFirstAdminNeedsAnAddressAndBothNames(): void
    {
        foreach ([['ada', 'Ada', 'Lovelace'], ['ada@example.org', ' ', 'Lovelace'], ['ada@example.org', 'Ada', '']] as [$email, $first, $last]) {
            try {
                new FirstAdmin($email, $first, $last);
                self::fail('Accepted ' . $email . ' ' . $first . ' ' . $last);
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testTheStepsSetUpTheFirstAdminInOrder(): void
    {
        $auth = new AuthFixture();
        $event = $this->event($auth);

        new CreateFirstAdmin($auth->clock)($event);
        new SeedRoles()($event);
        new FirstPasswordLink()($event);

        $admin = $auth->users->findByEmail('grace@example.org');
        self::assertNotNull($admin);
        self::assertSame($admin->id(), $event->adminId());
        self::assertTrue($admin->isPending());
        self::assertSame('Europe/London', $admin->timezone(), 'the site\'s time zone');
        self::assertSame(['admin'], array_map(static fn ($r) => $r->name(), $auth->database->roles()->rolesOf((int) $admin->id())));
        self::assertSame('Created the account of the first admin, Grace Hopper (grace@example.org).', $event->lines()[0]);
        self::assertSame('The roles were already set up.', $event->lines()[1]);
        self::assertSame('Gave the first admin the admin role.', $event->lines()[2]);
        self::assertSame(1, preg_match('#works once, for 7 days: http://localhost:8080/password/reset/(\S+)$#', $event->lines()[3], $link));
        self::assertSame($admin, $auth->resetTokens()->verify($link[1]));
    }

    public function testTheRolesAreNamedInASentence(): void
    {
        $database = new CoreSiteDatabase();
        $event = new SiteProvisioning(SiteFixture::site(), new Container([
            RbacSeeder::class => $database->seeder(),
            RoleRepository::class => $database->roles(),
        ]), new FirstAdmin('a@example.org', 'A', 'B'), 'https://demo.example');

        new SeedRoles()($event);

        self::assertSame(['Set up the roles admin, editor, contributor and member.'], $event->lines(), 'no admin yet, so no role given');
    }

    public function testWithoutAnAdminThereIsNoLink(): void
    {
        $auth = new AuthFixture();
        $event = $this->event($auth);

        new FirstPasswordLink()($event);
        $event->setAdminId(999);
        new FirstPasswordLink()($event);

        self::assertSame([], $event->lines());
    }

    private function event(AuthFixture $auth): SiteProvisioning
    {
        return new SiteProvisioning(SiteFixture::site(), new Container([
            UserRepository::class => $auth->users,
            Accounts::class => $auth->users,
            RbacSeeder::class => $auth->database->seeder(),
            RoleRepository::class => $auth->database->roles(),
            PasswordResetTokens::class => $auth->resetTokens(),
        ]), new FirstAdmin('grace@example.org', 'Grace', 'Hopper'), 'http://localhost:8080/');
    }
}
