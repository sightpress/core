<?php

declare(strict_types=1);

namespace SightPress\Core\People\Command;

use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:role:grant <slug> <role> <permission>`: gives a role one more permission.
 */
final readonly class GrantRoleCommand extends RoleCommand
{
    public function name(): string
    {
        return 'site:role:grant';
    }

    public function description(): string
    {
        return 'Gives a role on a site one more permission.';
    }

    public function parameters(): array
    {
        return [...parent::parameters(), new CommandParameter('permission', 'Permission, for example page.publish:', required: true, label: 'Permission')];
    }

    protected function change(Roles $roles, CommandInput $input): array
    {
        $role = self::role($input);
        $permission = trim((string) $input->argument('permission'));

        return [$roles->grant(null, $role, $permission)
            ? sprintf('The role %s now has %s. Permissions: %s.', $role, $permission, self::names($roles->find($role)->permissions ?? []))
            : sprintf('The role %s already has %s.', $role, $permission)];
    }
}
