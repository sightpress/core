<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Core\Access\PermissionCatalogue;
use SightPress\Core\Rbac\Entity\Permission;
use SightPress\Core\Rbac\Entity\Role;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * Brings a site's roles and permissions in line with the catalogue. Safe to run again: on site creation, after
 * migrations and when a module is turned on.
 *
 * - Every permission in the catalogue is stored, and kept up to date; permissions of modules that are off stay.
 * - The seeded roles (`admin`, `editor`, `contributor`, `member`) are created when missing.
 * - A permission new to the site is granted to `admin` and to its default roles, under {@see RoleManager}'s rules:
 *   a grant to `member` or an unsafe grant to the sign-up role is skipped and reported. Existing grants are never
 *   touched, so an admin's changes stay.
 * - The `registration_role` setting is stored as `member` when the site has none.
 */
final readonly class RbacSeeder
{
    public function __construct(
        private PermissionCatalogue $catalogue,
        private RoleRepository $roles,
        private RoleManager $manager,
        private SiteSettings $settings,
    ) {
    }

    public function seed(): SeedReport
    {
        $addedPermissions = [];
        foreach ($this->catalogue->all() as $definition) {
            $module = (string) $this->catalogue->moduleOf($definition->name);
            $stored = $this->roles->findPermission($definition->name);
            if ($stored === null) {
                $this->roles->save(new Permission($definition, $module));
                $addedPermissions[] = $definition->name;
            } else {
                $stored->update($definition, $module);
                $this->roles->save($stored);
            }
        }

        $addedRoles = [];
        foreach (RoleName::seeded() as $name => $role) {
            if ($this->roles->findRole($name) === null) {
                $this->roles->save(new Role($name, $role['label'], $role['system']));
                $addedRoles[] = $name;
            }
        }

        if ($this->settings->get(RegistrationRole::SETTING) === null) {
            $this->settings->set(RegistrationRole::SETTING, RoleName::MEMBER);
        }

        $skipped = [];
        foreach ($addedPermissions as $name) {
            $definition = $this->catalogue->get($name);
            $targets = array_values(array_unique([RoleName::ADMIN, ...($definition->defaultRoles ?? [])]));
            foreach ($targets as $roleName) {
                $role = $this->roles->findRole($roleName);
                if ($role === null) {
                    $skipped[] = sprintf('"%s" was not given to "%s": there is no such role.', $name, $roleName);
                    continue;
                }
                try {
                    $this->manager->grant($role, $name);
                } catch (RbacException $exception) {
                    $skipped[] = sprintf('"%s" was not given to "%s": %s', $name, $roleName, $exception->getMessage());
                }
            }
        }

        return new SeedReport($addedPermissions, $addedRoles, $skipped);
    }
}
