<?php

declare(strict_types=1);

namespace SightPress\Core\People\Command;

use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:role:revoke <slug> <role> <permission>`: takes a permission away from a role.
 */
final readonly class RevokeRoleCommand extends RoleCommand
{
    public function name(): string
    {
        return 'site:role:revoke';
    }

    public function description(): string
    {
        return 'Takes a permission away from a role on a site.';
    }

    public function parameters(): array
    {
        return [...parent::parameters(), new CommandParameter('permission', 'Permission, for example page.publish:', required: true, label: 'Permission')];
    }

    protected function change(Roles $roles, CommandInput $input): array
    {
        $role = self::role($input);
        $permission = trim((string) $input->argument('permission'));

        return [$roles->revoke(null, $role, $permission)
            ? sprintf('The role %s no longer has %s. Permissions: %s.', $role, $permission, self::names($roles->find($role)->permissions ?? []))
            : sprintf('The role %s doesn\'t have %s.', $role, $permission)];
    }
}
