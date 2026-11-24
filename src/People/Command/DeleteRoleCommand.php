<?php

declare(strict_types=1);

namespace SightPress\Core\People\Command;

use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Module\Console\CommandInput;

/**
 * `site:role:delete <slug> <role>`: deletes a role nobody has. Built-in roles and the sign-up role can't be deleted.
 */
final readonly class DeleteRoleCommand extends RoleCommand
{
    public function name(): string
    {
        return 'site:role:delete';
    }

    public function description(): string
    {
        return 'Deletes a role that nobody on the site has.';
    }

    protected function change(Roles $roles, CommandInput $input): array
    {
        $role = self::role($input);
        $roles->delete(null, $role);

        return [sprintf('Deleted the role %s.', $role)];
    }
}
