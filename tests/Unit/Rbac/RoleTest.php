<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\Entity\Permission;
use SightPress\Core\Rbac\Entity\Role;
use SightPress\Core\Rbac\Entity\RoleAssignment;
use SightPress\Core\Rbac\RbacException;
use SightPress\Module\Access\Permission as PermissionDefinition;

#[CoversClass(Role::class)]
#[CoversClass(Permission::class)]
#[CoversClass(RoleAssignment::class)]
final class RoleTest extends TestCase
{
    public function testHoldsPermissionsOnceAndSorted(): void
    {
        $role = new Role('writer', ' Writer ');
        $create = $this->permission('page.create');

        $role->addPermission($this->permission('page.submit'));
        $role->addPermission($create);
        $role->addPermission($create);

        self::assertNull($role->id());
        self::assertSame('writer', $role->name());
        self::assertSame('Writer', $role->label());
        self::assertFalse($role->isSystem());
        self::assertSame(['page.create', 'page.submit'], $role->permissionNames());
        self::assertCount(2, $role->permissions());
        self::assertTrue($role->hasPermission('page.create'));

        $role->removePermission('page.create');
        self::assertSame(['page.submit'], $role->permissionNames());
        self::assertFalse($role->hasPermission('page.create'));
    }

    public function testRenames(): void
    {
        $role = new Role('member', 'Member', true);
        self::assertTrue($role->isSystem());

        $role->rename('reader', 'Reader');

        self::assertSame('reader', $role->name());
        self::assertSame('Reader', $role->label());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRoles(): iterable
    {
        yield 'upper case' => ['Editor', 'Editor'];
        yield 'starts with a digit' => ['1st', 'First'];
        yield 'too long' => [str_repeat('a', 51), 'Long'];
        yield 'empty label' => ['writer', '  '];
        yield 'long label' => ['writer', str_repeat('x', 101)];
    }

    #[DataProvider('invalidRoles')]
    public function testRefusesBadNamesAndLabels(string $name, string $label): void
    {
        $this->expectException(RbacException::class);

        new Role($name, $label);
    }

    public function testAStoredPermissionCopiesItsDefinition(): void
    {
        $permission = new Permission(new PermissionDefinition('page.create', 'Create pages', 'Start pages.', [], true), 'rbac');

        self::assertSame('page.create', $permission->name());
        self::assertSame('Create pages', $permission->label());
        self::assertSame('Start pages.', $permission->description());
        self::assertSame('rbac', $permission->module());
        self::assertTrue($permission->allowedOnSignUp());

        $permission->update(new PermissionDefinition('page.create', 'Start pages', 'New text.'), 'page');

        self::assertSame('Start pages', $permission->label());
        self::assertSame('New text.', $permission->description());
        self::assertSame('page', $permission->module());
        self::assertFalse($permission->allowedOnSignUp());
    }

    public function testAnAssignmentLinksAUserIdToARole(): void
    {
        $role = new Role('editor', 'Editor');
        $assignment = new RoleAssignment(7, $role);

        self::assertSame(7, $assignment->userId());
        self::assertSame($role, $assignment->role());
    }

    private function permission(string $name): Permission
    {
        return new Permission(new PermissionDefinition($name, $name), 'rbac');
    }
}
