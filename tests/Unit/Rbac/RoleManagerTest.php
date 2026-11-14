<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\Entity\Role;
use SightPress\Core\Rbac\RbacException;
use SightPress\Core\Rbac\RoleManager;
use SightPress\Core\Tests\Support\CoreSiteDatabase;

#[CoversClass(RoleManager::class)]
#[UsesNamespace('SightPress\Core')]
final class RoleManagerTest extends TestCase
{
    private CoreSiteDatabase $database;

    private RoleManager $manager;

    protected function setUp(): void
    {
        $this->database = new CoreSiteDatabase();
        $this->database->seeder()->seed();
        $this->manager = $this->database->manager();
    }

    public function testACustomRoleWithAnyPermissionGetsAdminAccess(): void
    {
        $role = $this->manager->create('reviewer', 'Reviewer', ['page.publish', 'page.publish']);

        self::assertIsInt($role->id());
        self::assertSame(['admin.access', 'page.publish'], $role->permissionNames());
        self::assertSame($role, $this->database->roles()->findRole('reviewer'));
    }

    public function testACustomRoleWithoutPermissionsHasNone(): void
    {
        self::assertSame([], $this->manager->create('guest', 'Guest')->permissionNames());
    }

    public function testRoleNamesAreUnique(): void
    {
        $this->expectException(RbacException::class);
        $this->expectExceptionMessage('A role called "editor" already exists.');

        $this->manager->create('editor', 'Second editor');
    }

    public function testUnknownPermissionsAreRefused(): void
    {
        $this->expectException(RbacException::class);
        $this->expectExceptionMessage('There is no permission called "acme.missing".');

        $this->manager->grant($this->role('editor'), 'acme.missing');
    }

    public function testAnyGrantToMemberIsRefused(): void
    {
        foreach (['admin.access', 'page.create', 'settings.manage'] as $permission) {
            try {
                $this->manager->grant($this->role('member'), $permission);
                self::fail(sprintf('"%s" was granted to member.', $permission));
            } catch (RbacException $exception) {
                self::assertStringContainsString('"member" role can\'t be given any permission', $exception->getMessage());
            }
        }

        self::assertSame([], $this->role('member')->permissionNames());
    }

    public function testAnUnflaggedGrantToTheSignUpRoleIsRefused(): void
    {
        $this->database->registrationRole()->change('contributor');

        $this->manager->grant($this->role('contributor'), 'page.create');

        $this->expectException(RbacException::class);
        $this->expectExceptionMessage('Choose another sign-up role first.');
        $this->manager->grant($this->role('contributor'), 'page.publish');
    }

    public function testUnflaggedGrantsAreFineForRolesThatArentTheSignUpRole(): void
    {
        $this->manager->grant($this->role('contributor'), 'page.publish');

        self::assertTrue($this->role('contributor')->hasPermission('page.publish'));
    }

    public function testSetPermissionsReplacesTheSet(): void
    {
        $role = $this->role('editor');

        $this->manager->setPermissions($role, ['menu.manage']);
        self::assertSame(['admin.access', 'menu.manage'], $role->permissionNames());

        $this->manager->setPermissions($role, []);
        self::assertSame([], $role->permissionNames());
    }

    public function testRevokingKeepsAdminAccessWhileOtherPermissionsRemain(): void
    {
        $role = $this->manager->create('reviewer', 'Reviewer', ['page.publish']);

        try {
            $this->manager->revoke($role, 'admin.access');
            self::fail('admin.access was revoked while page.publish remained.');
        } catch (RbacException $exception) {
            self::assertStringContainsString('Remove those first', $exception->getMessage());
        }

        $this->manager->revoke($role, 'page.publish');
        self::assertSame(['admin.access'], $role->permissionNames());
        $this->manager->revoke($role, 'admin.access');
        self::assertSame([], $role->permissionNames());
    }

    public function testSystemRolesCantBeRenamedOrDeleted(): void
    {
        foreach (['member', 'admin'] as $name) {
            $role = $this->role($name);
            try {
                $this->manager->rename($role, 'renamed', 'Renamed');
                self::fail(sprintf('"%s" was renamed.', $name));
            } catch (RbacException $exception) {
                self::assertStringContainsString('can\'t be renamed', $exception->getMessage());
            }
            try {
                $this->manager->delete($role);
                self::fail(sprintf('"%s" was deleted.', $name));
            } catch (RbacException $exception) {
                self::assertStringContainsString('can\'t be deleted', $exception->getMessage());
            }
        }

        $member = $this->role('member');
        $this->manager->rename($member, 'member', 'Reader');
        self::assertSame('Reader', $member->label());
    }

    public function testRenamingChecksTheNewName(): void
    {
        $role = $this->role('editor');
        $this->manager->rename($role, 'senior_editor', 'Senior editor');
        self::assertSame('senior_editor', $this->role('senior_editor')->name());

        $this->expectException(RbacException::class);
        $this->expectExceptionMessage('A role called "contributor" already exists.');
        $this->manager->rename($role, 'contributor', 'Contributor');
    }

    public function testTheSignUpRoleCantBeRenamedOrDeleted(): void
    {
        $this->database->registrationRole()->change('contributor');
        $role = $this->role('contributor');

        try {
            $this->manager->rename($role, 'writer', 'Writer');
            self::fail('The sign-up role was renamed.');
        } catch (RbacException $exception) {
            self::assertStringContainsString('Choose another sign-up role first.', $exception->getMessage());
        }

        $this->expectException(RbacException::class);
        $this->expectExceptionMessage('Choose another sign-up role first.');
        $this->manager->delete($role);
    }

    public function testARoleInUseCantBeDeleted(): void
    {
        $role = $this->role('editor');
        $this->database->roles()->assign(1, $role);
        $this->database->roles()->assign(2, $role);

        try {
            $this->manager->delete($role);
            self::fail('A role in use was deleted.');
        } catch (RbacException $exception) {
            self::assertSame('2 people have the "editor" role. Give them another role first.', $exception->getMessage());
        }

        $this->database->roles()->assign(2, $this->role('contributor'));
        try {
            $this->manager->delete($role);
            self::fail('A role in use was deleted.');
        } catch (RbacException $exception) {
            self::assertSame('1 person has the "editor" role. Give them another role first.', $exception->getMessage());
        }

        $this->database->roles()->assign(1, $this->role('contributor'));
        $this->manager->delete($role);
        self::assertNull($this->database->roles()->findRole('editor'));
    }

    private function role(string $name): Role
    {
        $role = $this->database->roles()->findRole($name);
        self::assertNotNull($role);

        return $role;
    }
}
