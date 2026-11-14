<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\CoreSiteDatabase;

#[CoversClass(RoleRepository::class)]
#[UsesNamespace('SightPress\Core')]
final class RoleRepositoryTest extends TestCase
{
    public function testListsRolesPermissionsAndWhatAUserHolds(): void
    {
        $database = new CoreSiteDatabase();
        $database->seeder()->seed();
        $roles = $database->roles();

        self::assertSame(
            ['admin', 'contributor', 'editor', 'member'],
            array_map(static fn ($role) => $role->name(), $roles->roles()),
        );
        self::assertCount(17, $roles->permissions());
        self::assertSame('admin.access', $roles->permissions()[0]->name());
        self::assertNull($roles->findRole('ghost'));
        self::assertNull($roles->findPermission('acme.missing'));

        self::assertSame([], $roles->rolesOf(5));
        self::assertSame([], $roles->permissionNamesOf(5));

        $contributor = $roles->findRole('contributor');
        $editor = $roles->findRole('editor');
        self::assertNotNull($contributor);
        self::assertNotNull($editor);
        $roles->assign(5, $contributor);
        self::assertSame(['contributor'], array_map(static fn ($role) => $role->name(), $roles->rolesOf(5)));
        self::assertSame(1, $roles->countUsersWith($contributor));

        $roles->assign(5, $editor);
        $database->entityManager->clear();
        self::assertSame(['editor'], array_map(static fn ($role) => $role->name(), $roles->rolesOf(5)));
        self::assertContains('page.publish', $roles->permissionNamesOf(5));
        self::assertSame($roles->permissionNamesOf(5), array_values(array_unique($roles->permissionNamesOf(5))));
    }

    public function testRemovesARole(): void
    {
        $database = new CoreSiteDatabase();
        $roles = $database->roles();
        $role = new \SightPress\Core\Rbac\Entity\Role('guest', 'Guest');
        $roles->save($role);
        self::assertSame($role, $roles->findRole('guest'));

        $roles->remove($role);

        self::assertNull($roles->findRole('guest'));
    }

    public function testPermissionsOfSeveralRolesAreMergedOnce(): void
    {
        $database = new CoreSiteDatabase();
        $database->seeder()->seed();
        $roles = $database->roles();
        $contributor = $roles->findRole('contributor');
        $editor = $roles->findRole('editor');
        self::assertNotNull($contributor);
        self::assertNotNull($editor);
        $database->entityManager->persist(new \SightPress\Core\Rbac\Entity\RoleAssignment(9, $contributor));
        $database->entityManager->persist(new \SightPress\Core\Rbac\Entity\RoleAssignment(9, $editor));
        $database->entityManager->flush();

        $names = $roles->permissionNamesOf(9);

        self::assertSame($names, array_values(array_unique($names)));
        self::assertCount(12, $names);
    }
}
