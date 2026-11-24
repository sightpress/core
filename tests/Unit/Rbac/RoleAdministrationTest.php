<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\RoleEdited;
use SightPress\Core\Rbac\Contract\RoleRefused;
use SightPress\Core\Rbac\Contract\RoleSummary;
use SightPress\Core\Rbac\Entity\Permission;
use SightPress\Core\Rbac\RoleAdministration;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Module\Access\Permission as PermissionDefinition;

#[CoversClass(RoleAdministration::class)]
#[CoversClass(RoleRefused::class)]
#[CoversClass(RoleSummary::class)]
#[CoversClass(\SightPress\Core\Rbac\Contract\PermissionSummary::class)]
#[CoversClass(RoleEdited::class)]
#[UsesNamespace('SightPress\Core')]
final class RoleAdministrationTest extends TestCase
{
    private CoreSiteDatabase $database;

    private RecordingEvents $events;

    private RoleAdministration $roles;

    protected function setUp(): void
    {
        $this->database = new CoreSiteDatabase();
        $this->database->seeder()->seed();
        $this->events = new RecordingEvents();
        $this->roles = $this->database->roleAdministration($this->events);
    }

    public function testListsTheSeededRolesFirstThenTheOthersByName(): void
    {
        $this->roles->create(null, 'zebra', 'Zebra', []);
        $this->roles->create(null, 'auditor', 'Auditor', ['audit.view']);

        self::assertSame(['admin', 'editor', 'contributor', 'member', 'auditor', 'zebra'], array_map(static fn (RoleSummary $role): string => $role->name, $this->roles->roles()));
        $member = $this->roles->find('member');
        self::assertEquals(new RoleSummary('member', 'Member', [], 0, true, true), $member);
        self::assertEquals(new RoleSummary('auditor', 'Auditor', ['admin.access', 'audit.view'], 0, false, false), $this->roles->find('auditor'));
        self::assertNull($this->roles->find('nobody'));
        self::assertTrue($this->roles->find('admin')?->fixed);
    }

    public function testOnlyPermissionsOfModulesThatAreOnCanBeGiven(): void
    {
        $this->hiddenPermission();

        $names = array_map(static fn ($permission): string => $permission->name, $this->roles->permissions());
        self::assertContains('page.publish', $names);
        self::assertNotContains('acme.export', $names);
        $publish = array_values(array_filter($this->roles->permissions(), static fn ($permission): bool => $permission->name === 'page.create'))[0];
        self::assertSame(['Create pages', 'Start new pages.', true], [$publish->label, $publish->description, $publish->allowedOnSignUp]);

        $refused = $this->refused(fn () => $this->roles->create(null, 'exporter', 'Exporter', ['acme.export']));
        self::assertSame(['acme.export' => 'There is no permission called “acme.export” that this site can use.'], $refused);
    }

    public function testMakesAFreeNameFromALabel(): void
    {
        self::assertSame('shop_staff', $this->roles->nameFor('Shop staff!'));
        self::assertSame('equipa_da_loja', $this->roles->nameFor('Equipa da Loja'));
        self::assertSame('editor_2', $this->roles->nameFor('Editor'));
        self::assertSame('role_2024_team', $this->roles->nameFor('2024 team'));
        self::assertSame('role', $this->roles->nameFor('!!!'));
        self::assertSame(40, strlen($this->roles->nameFor(str_repeat('a', 80))));
        $this->roles->create(null, 'editor_2', 'Second editor', []);
        self::assertSame('editor_3', $this->roles->nameFor('Editor'));
    }

    public function testCreatesARoleAndSaysSo(): void
    {
        $role = $this->roles->create(new Actor(7, ['role.manage']), 'shop', '  Shop staff ', ['media.upload']);

        self::assertEquals(new RoleSummary('shop', 'Shop staff', ['admin.access', 'media.upload'], 0, false, false), $role);
        self::assertEquals([new RoleEdited(RoleEdited::CREATED, 'shop', 'Shop staff', 7, ['admin.access', 'media.upload'])], $this->events->events);
    }

    public function testANewRoleNeedsAValidFreeNameAndLabel(): void
    {
        self::assertSame([
            'label' => 'Enter a name for the role of 1 to 100 characters.',
            'name' => 'A role name is 1 to 50 lower-case letters, digits and “_”, starting with a letter, for example shop_staff.',
        ], $this->refused(fn () => $this->roles->create(null, 'Bad name', ' ', [])));
        self::assertSame(['name' => 'A role name is 1 to 50 lower-case letters, digits and “_”, starting with a letter, for example shop_staff.'], $this->refused(fn () => $this->roles->create(null, str_repeat('a', 51), 'Long', [])));
        self::assertSame(['label' => 'Enter a name for the role of 1 to 100 characters.'], $this->refused(fn () => $this->roles->create(null, 'long', str_repeat('a', 101), [])));
        self::assertSame([
            'name' => 'There is already a role called “editor”.',
            'label' => 'There is already a role called “Editor”. Choose another name.',
        ], $this->refused(fn () => $this->roles->create(null, 'editor', 'EDITOR', [])));
        self::assertSame([], $this->events->events);
    }

    public function testAdminAndMemberCantBeChanged(): void
    {
        self::assertSame(['role' => 'The Admin role always has every permission, so it can’t be changed.'], $this->refused(fn () => $this->roles->revoke(null, 'admin', 'page.publish')));
        self::assertSame(['role' => 'The Member role never has any permission, so it can’t be changed.'], $this->refused(fn () => $this->roles->grant(null, 'member', 'page.create')));
        self::assertSame(['role' => 'The Member role never has any permission, so it can’t be changed.'], array_map(strval(...), $this->roles->problems(null, 'member', 'member', 'Members', [])));
        self::assertSame([], $this->database->roles()->findRole('member')?->permissionNames());
    }

    public function testChangesTheLabelAndPermissionsAndKeepsThoseOfModulesThatAreOff(): void
    {
        $this->hiddenPermission('contributor');
        $actor = new Actor(1, ['role.manage']);

        self::assertTrue($this->roles->update($actor, 'contributor', 'Writer', ['page.create', 'page.publish']));

        $role = $this->database->roles()->findRole('contributor');
        self::assertSame('Writer', $role?->label());
        self::assertSame(['acme.export', 'admin.access', 'page.create', 'page.publish'], $role->permissionNames());
        self::assertEquals([
            new RoleEdited(RoleEdited::RENAMED, 'contributor', 'Writer', 1, from: 'Contributor'),
            new RoleEdited(RoleEdited::PERMISSIONS, 'contributor', 'Writer', 1, ['page.publish'], ['media.upload', 'page.edit.own', 'page.submit']),
        ], $this->events->events);

        $this->events->events = [];
        self::assertFalse($this->roles->update($actor, 'contributor', 'Writer', ['page.create', 'page.publish', 'admin.access']));
        self::assertSame([], $this->events->events);
        self::assertSame(['label' => 'There is already a role called “Editor”. Choose another name.'], $this->refused(fn () => $this->roles->update($actor, 'contributor', 'editor', [])));
        self::assertSame(['role' => 'There is no role called “ghost”. See the roles with site:role:list.'], $this->refused(fn () => $this->roles->update($actor, 'ghost', 'Ghost', [])));
    }

    public function testGrantsAndRevokesOnePermission(): void
    {
        $this->roles->create(null, 'helper', 'Helper', []);

        self::assertTrue($this->roles->grant(null, 'helper', 'menu.manage'));
        self::assertFalse($this->roles->grant(null, 'helper', 'menu.manage'));
        self::assertSame(['admin.access', 'menu.manage'], $this->roles->find('helper')?->permissions);
        self::assertSame(['admin.access' => '“Helper” keeps “Use the admin panel” while it has other permissions. Take those away first.'], $this->refused(fn () => $this->roles->revoke(null, 'helper', 'admin.access')));
        self::assertFalse($this->roles->revoke(null, 'helper', 'page.publish'));
        self::assertSame(['acme.nope' => 'There is no permission called “acme.nope” that this site can use.'], $this->refused(fn () => $this->roles->revoke(null, 'helper', 'acme.nope')));
        self::assertTrue($this->roles->revoke(null, 'helper', 'menu.manage'));
        self::assertSame(['admin.access'], $this->roles->find('helper')?->permissions);
        self::assertTrue($this->roles->revoke(null, 'helper', 'admin.access'));
        self::assertSame([], $this->roles->find('helper')?->permissions);
    }

    public function testTheSignUpRoleOnlyGetsPermissionsThatAreSafeOnSignUp(): void
    {
        $this->database->registrationRole()->change('contributor');

        self::assertTrue($this->roles->find('contributor')?->signUp);
        self::assertSame(['page.publish' => '“Contributor” is the role of people who sign up, and they can’t get “Publish pages”: it isn’t safe for people you don’t know. Choose another sign-up role first.'], $this->refused(fn () => $this->roles->grant(null, 'contributor', 'page.publish')));
        self::assertTrue($this->roles->revoke(null, 'contributor', 'media.upload'));
        self::assertTrue($this->roles->grant(null, 'contributor', 'media.upload'), 'safe permissions can be given');
    }

    public function testNobodyTakesAwayTheirOwnLastWayToManageRoles(): void
    {
        $this->roles->create(null, 'manager', 'Manager', ['role.manage', 'user.manage']);
        $this->database->roles()->assign(5, $this->database->roles()->findRole('manager') ?? self::fail());
        $actor = new Actor(5, ['admin.access', 'role.manage', 'user.manage']);

        self::assertSame(['role.manage' => 'You manage roles through the “Manager” role, so you can’t take “Manage roles” away from it. Ask another admin to do it.'], $this->refused(fn () => $this->roles->revoke($actor, 'manager', 'role.manage')));
        self::assertTrue($this->roles->revoke(new Actor(1, ['role.manage']), 'manager', 'role.manage'), 'another admin can');
        self::assertTrue($this->roles->grant($actor, 'manager', 'menu.manage'), 'someone who couldn\'t manage roles isn\'t stopped');
        self::assertTrue($this->roles->revoke(new Actor(null), 'manager', 'menu.manage'));
    }

    public function testDeletesOnlyARoleNobodyHas(): void
    {
        $this->roles->create(null, 'helper', 'Helper', []);
        $this->roles->create(null, 'busy', 'Busy', []);
        $this->database->roles()->assign(3, $this->database->roles()->findRole('busy') ?? self::fail());
        $this->database->roles()->assign(4, $this->database->roles()->findRole('busy') ?? self::fail());
        $this->database->roles()->assign(5, $this->database->roles()->findRole('editor') ?? self::fail());
        $this->events->events = [];

        self::assertSame('“Admin” is built in, so it can’t be deleted.', (string) $this->roles->deleteProblem('admin'));
        self::assertSame('“Member” is built in, so it can’t be deleted.', (string) $this->roles->deleteProblem('member'));
        self::assertSame('2 people have the “Busy” role. Give them another role first.', (string) $this->roles->deleteProblem('busy'));
        self::assertSame('1 person has the “Editor” role. Give them another role first.', (string) $this->roles->deleteProblem('editor'));
        $this->database->registrationRole()->change('contributor');
        self::assertSame('“Contributor” is the role of people who sign up. Choose another sign-up role in the sign-up settings first.', (string) $this->roles->deleteProblem('contributor'));
        self::assertSame(['role' => '2 people have the “Busy” role. Give them another role first.'], $this->refused(fn () => $this->roles->delete(null, 'busy')));
        self::assertNull($this->roles->deleteProblem('helper'));

        $this->roles->delete(new Actor(1, ['role.manage']), 'helper');

        self::assertNull($this->roles->find('helper'));
        self::assertEquals([new RoleEdited(RoleEdited::DELETED, 'helper', 'Helper', 1)], $this->events->events);
    }

    private function hiddenPermission(?string $role = null): void
    {
        $permission = new Permission(new PermissionDefinition('acme.export', 'Export', 'Export everything.'), 'acme.gone');
        $this->database->roles()->save($permission);
        if ($role !== null) {
            $entity = $this->database->roles()->findRole($role) ?? self::fail();
            $entity->addPermission($permission);
            $this->database->roles()->save($entity);
        }
    }

    /**
     * @return array<string, string>
     */
    private function refused(callable $change): array
    {
        try {
            $change();
        } catch (RoleRefused $refused) {
            self::assertSame((string) array_values($refused->problems)[0], $refused->getMessage());

            return array_map(strval(...), $refused->problems);
        }
        self::fail('The change was not refused.');
    }
}
