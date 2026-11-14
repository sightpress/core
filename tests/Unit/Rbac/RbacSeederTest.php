<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\SeedReport;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Module\Access\Permission;
use SightPress\Module\ModuleRegistrar;

#[CoversClass(RbacSeeder::class)]
#[CoversClass(SeedReport::class)]
#[UsesNamespace('SightPress\Core')]
final class RbacSeederTest extends TestCase
{
    public function testSeedsTheCatalogueTheRolesAndTheSignUpRole(): void
    {
        $database = new CoreSiteDatabase();

        $report = $database->seeder()->seed();

        self::assertCount(17, $report->addedPermissions);
        self::assertSame(['admin', 'editor', 'contributor', 'member'], $report->addedRoles);
        self::assertSame([], $report->skippedGrants);
        self::assertSame('member', $database->registrationRole()->name());

        $roles = $database->roles();
        self::assertCount(17, $roles->findRole('admin')?->permissionNames() ?? []);
        self::assertSame(
            ['admin.access', 'media.publish', 'media.upload', 'menu.manage', 'page.archive', 'page.create',
                'page.delete', 'page.edit.any', 'page.edit.own', 'page.publish', 'page.submit', 'user.invite'],
            $roles->findRole('editor')?->permissionNames(),
        );
        self::assertSame(
            ['admin.access', 'media.upload', 'page.create', 'page.edit.own', 'page.submit'],
            $roles->findRole('contributor')?->permissionNames(),
        );
        self::assertSame([], $roles->findRole('member')?->permissionNames());
        self::assertSame('rbac', $roles->findPermission('page.create')?->module());
        self::assertSame('user', $roles->findPermission('user.invite')?->module());
    }

    public function testRunningAgainKeepsAnAdminsChanges(): void
    {
        $database = new CoreSiteDatabase();
        $database->seeder()->seed();
        $editor = $database->roles()->findRole('editor');
        self::assertNotNull($editor);
        $database->manager()->revoke($editor, 'page.delete');
        $database->settings()->set('rbac.registration_role', 'contributor');

        $report = $database->seeder()->seed();

        self::assertEquals(new SeedReport(), $report);
        self::assertFalse($database->roles()->findRole('editor')?->hasPermission('page.delete'));
        self::assertSame('contributor', $database->registrationRole()->name());
    }

    public function testANewModulesPermissionsFollowTheRules(): void
    {
        $database = new CoreSiteDatabase([new ModuleCandidate(new FakeModule('acme', register: static function (ModuleRegistrar $registrar): void {
            $registrar->permissions()->add(new Permission('acme.read', 'Read', defaultRoles: ['member', 'contributor', 'ghost']));
            $registrar->permissions()->add(new Permission('acme.write', 'Write', defaultRoles: ['contributor'], allowedOnSignUp: true));
        }), 'acme/module')]);
        $database->settings()->set('rbac.registration_role', 'contributor');

        $report = $database->seeder()->seed();

        self::assertSame(['acme.read', 'acme.write'], array_slice($report->addedPermissions, -2));
        self::assertCount(3, $report->skippedGrants);
        self::assertStringStartsWith('"acme.read" was not given to "member": The "member" role', $report->skippedGrants[0]);
        self::assertStringStartsWith('"acme.read" was not given to "contributor": The "contributor" role is the sign-up role', $report->skippedGrants[1]);
        self::assertSame('"acme.read" was not given to "ghost": there is no such role.', $report->skippedGrants[2]);

        $roles = $database->roles();
        $admin = $roles->findRole('admin');
        $contributor = $roles->findRole('contributor');
        $member = $roles->findRole('member');
        self::assertNotNull($admin);
        self::assertNotNull($contributor);
        self::assertNotNull($member);
        self::assertTrue($admin->hasPermission('acme.read'));
        self::assertTrue($contributor->hasPermission('acme.write'));
        self::assertFalse($contributor->hasPermission('acme.read'));
        self::assertSame([], $member->permissionNames());
    }

    public function testStoredPermissionsFollowTheirDefinition(): void
    {
        $database = new CoreSiteDatabase();
        $database->seeder()->seed();
        $database->connection->update('permissions', ['label' => 'Old label', 'allowed_on_sign_up' => true], ['name' => 'page.publish']);
        $database->entityManager->clear();

        $database->seeder()->seed();

        $permission = $database->roles()->findPermission('page.publish');
        self::assertNotNull($permission);
        self::assertSame('Publish pages', $permission->label());
        self::assertFalse($permission->allowedOnSignUp());
    }
}
