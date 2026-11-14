<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Core\Rbac\Entity\Role;

/**
 * Creates, changes and deletes roles with every RBAC rule applied:
 *
 * - `member` can never hold a permission (D24), and system roles can't be renamed or deleted;
 * - a role that holds any permission also holds `admin.access`, and can't lose it while it holds others;
 * - the site's sign-up role can't get a permission that isn't allowed on sign-up (ADR-0014);
 * - a role still in use, or the sign-up role, can't be deleted.
 */
final readonly class RoleManager
{
    public function __construct(
        private RoleRepository $roles,
        private RegistrationRole $registrationRole,
    ) {
    }

    /**
     * @param list<string> $permissions
     */
    public function create(string $name, string $label, array $permissions = []): Role
    {
        if ($this->roles->findRole($name) !== null) {
            throw new RbacException(sprintf('A role called "%s" already exists.', $name));
        }

        $role = new Role($name, $label);
        $this->apply($role, $permissions);

        return $role;
    }

    /**
     * Replaces the role's permissions; `admin.access` is added when the list isn't empty.
     *
     * @param list<string> $permissions
     */
    public function setPermissions(Role $role, array $permissions): void
    {
        $this->apply($role, $permissions);
    }

    public function grant(Role $role, string $permission): void
    {
        $this->apply($role, [...$role->permissionNames(), $permission]);
    }

    public function revoke(Role $role, string $permission): void
    {
        $remaining = array_values(array_diff($role->permissionNames(), [$permission]));
        if ($permission === CorePermissions::ADMIN_ACCESS && $remaining !== []) {
            throw new RbacException(sprintf(
                'The "%s" role keeps "%s" while it has other permissions. Remove those first.',
                $role->name(),
                CorePermissions::ADMIN_ACCESS,
            ));
        }

        $this->apply($role, $remaining);
    }

    public function rename(Role $role, string $name, string $label): void
    {
        if ($role->isSystem() && $name !== $role->name()) {
            throw new RbacException(sprintf('The "%s" role is built in, so it can\'t be renamed.', $role->name()));
        }
        $other = $this->roles->findRole($name);
        if ($other !== null && $other !== $role) {
            throw new RbacException(sprintf('A role called "%s" already exists.', $name));
        }
        if ($this->registrationRole->is($role) && $name !== $role->name()) {
            throw new RbacException(sprintf(
                'The "%s" role is the sign-up role. Choose another sign-up role first.',
                $role->name(),
            ));
        }

        $role->rename($name, $label);
        $this->roles->save($role);
    }

    public function delete(Role $role): void
    {
        if ($role->isSystem()) {
            throw new RbacException(sprintf('The "%s" role is built in, so it can\'t be deleted.', $role->name()));
        }
        if ($this->registrationRole->is($role)) {
            throw new RbacException(sprintf(
                'The "%s" role is the sign-up role. Choose another sign-up role first.',
                $role->name(),
            ));
        }
        $users = $this->roles->countUsersWith($role);
        if ($users > 0) {
            throw new RbacException(sprintf(
                '%d %s the "%s" role. Give them another role first.',
                $users,
                $users === 1 ? 'person has' : 'people have',
                $role->name(),
            ));
        }

        $this->roles->remove($role);
    }

    /**
     * @param list<string> $names
     */
    private function apply(Role $role, array $names): void
    {
        $names = array_values(array_unique($names));
        if ($role->name() === RoleName::MEMBER && $names !== []) {
            throw new RbacException(
                'The "member" role can\'t be given any permission. Members only read the site and look after '
                . 'their own account; use another role for anything more.',
            );
        }
        if ($names !== [] && !in_array(CorePermissions::ADMIN_ACCESS, $names, true)) {
            $names[] = CorePermissions::ADMIN_ACCESS;
        }

        $permissions = [];
        foreach ($names as $name) {
            $permission = $this->roles->findPermission($name);
            if ($permission === null) {
                throw new RbacException(sprintf('There is no permission called "%s".', $name));
            }
            if ($this->registrationRole->is($role) && !$permission->allowedOnSignUp()) {
                throw new RbacException(sprintf(
                    'The "%s" role is the sign-up role, and people who sign up can\'t hold "%s". '
                    . 'Choose another sign-up role first.',
                    $role->name(),
                    $name,
                ));
            }
            $permissions[$name] = $permission;
        }

        foreach ($role->permissionNames() as $current) {
            if (!isset($permissions[$current])) {
                $role->removePermission($current);
            }
        }
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }

        $this->roles->save($role);
    }
}
