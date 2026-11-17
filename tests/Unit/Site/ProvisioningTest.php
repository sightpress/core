<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site;

use DI\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Provisioning\FirstSetupCode;
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
#[CoversClass(FirstSetupCode::class)]
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
        new FirstSetupCode()($event);

        $admin = $auth->users->findByEmail('grace@example.org');
        self::assertNotNull($admin);
        self::assertSame($admin->id(), $event->adminId());
        self::assertTrue($admin->isPending());
        self::assertSame('Europe/London', $admin->timezone(), 'the site\'s time zone');
        self::assertSame(\SightPress\Core\User\AvatarKind::Generated, $admin->avatarKind(), 'the first admin gets an avatar');
        self::assertStringContainsString('>GH</text>', $auth->storage()->read((string) $admin->avatarPath()));
        self::assertSame(['admin'], array_map(static fn ($r) => $r->name(), $auth->database->roles()->rolesOf((int) $admin->id())));
        self::assertSame('Created the account of the first admin, Grace Hopper (grace@example.org).', $event->lines()[0]);
        self::assertSame('The roles were already set up.', $event->lines()[1]);
        self::assertSame('Gave the first admin the admin role.', $event->lines()[2]);
        self::assertSame(1, preg_match(
            '#^Give the setup code (\d{3}) (\d{3}) to grace@example\.org\. They go to http://localhost:8080/login/setup, type their email address and the code, and choose their password\. The code works once, for 7 days\.$#',
            $event->lines()[3],
            $code,
        ));
        $check = $auth->codes()->check(VerificationPurpose::AccountSetup, VerificationChannel::Email, 'grace@example.org', $code[1] . $code[2], '192.0.2.1');
        self::assertTrue($check->isValid());
        self::assertSame($admin->id(), $check->accountId);
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

    public function testWithoutAnAdminThereIsNoCode(): void
    {
        $auth = new AuthFixture();
        $event = $this->event($auth);

        new FirstSetupCode()($event);
        $event->setAdminId(999);
        new FirstSetupCode()($event);

        self::assertSame([], $event->lines());
    }

    private function event(AuthFixture $auth): SiteProvisioning
    {
        return new SiteProvisioning(SiteFixture::site(), new Container([
            UserRepository::class => $auth->users,
            Accounts::class => $auth->users,
            RbacSeeder::class => $auth->database->seeder(),
            RoleRepository::class => $auth->database->roles(),
            VerificationCodes::class => $auth->codes(),
            \SightPress\Core\User\Avatar\AvatarService::class => $auth->avatars(),
        ]), new FirstAdmin('grace@example.org', 'Grace', 'Hopper'), 'http://localhost:8080/');
    }
}
