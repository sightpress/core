<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\Entity\Permission;
use SightPress\Core\Rbac\Entity\Role;
use SightPress\Core\Rbac\RoleSafety;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Module\Access\Permission as PermissionDefinition;

#[CoversClass(RoleSafety::class)]
#[UsesNamespace('SightPress\Core')]
final class RoleSafetyTest extends TestCase
{
    public function testMemberAndContributorAreSafeEditorAndAdminAreNot(): void
    {
        $database = new CoreSiteDatabase();
        $database->seeder()->seed();
        $roles = $database->roles();

        $safety = [];
        foreach (['admin', 'editor', 'contributor', 'member'] as $name) {
            $role = $roles->findRole($name);
            self::assertNotNull($role);
            $safety[$name] = RoleSafety::isSignUpSafe($role);
        }

        self::assertSame(['admin' => false, 'editor' => false, 'contributor' => true, 'member' => true], $safety);
    }

    public function testOnePermissionWithoutTheFlagMakesARoleUnsafe(): void
    {
        $role = new Role('writer', 'Writer');
        $role->addPermission(new Permission(new PermissionDefinition('page.create', 'Create', allowedOnSignUp: true), 'rbac'));
        self::assertTrue(RoleSafety::isSignUpSafe($role));

        $role->addPermission(new Permission(new PermissionDefinition('acme.export', 'Export'), 'acme'));
        self::assertFalse(RoleSafety::isSignUpSafe($role));
    }

    public function testAdminIsRefusedByNameEvenWithoutPermissions(): void
    {
        self::assertFalse(RoleSafety::isSignUpSafe(new Role('admin', 'Admin', true)));
    }
}
