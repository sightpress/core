<?php

declare(strict_types=1);

namespace SightPress\Core\People\Command;

use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:role:create <slug> <role> [--label=] [--permissions=a,b]`: a new role, with the label people see (by default
 * made from its name) and its permissions. `admin.access` is added when it has any.
 */
final readonly class CreateRoleCommand extends RoleCommand
{
    public function name(): string
    {
        return 'site:role:create';
    }

    public function description(): string
    {
        return 'Creates a role on a site, optionally with permissions.';
    }

    public function parameters(): array
    {
        $option = CommandParameterType::Option;

        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('role', 'Name of the new role: lower-case letters, digits and "_", for example shop_staff:', required: true, label: 'Role'),
            new CommandParameter('label', 'Name people see, for example "Shop staff" (default: made from the role name).', $option),
            new CommandParameter('permissions', 'Permissions, separated by commas, for example page.create,media.upload (default: none).', $option),
        ];
    }

    protected function change(Roles $roles, CommandInput $input): array
    {
        $name = trim((string) $input->argument('role'));
        $label = trim((string) $input->option('label'));
        $label = $label === '' ? ucfirst(str_replace('_', ' ', $name)) : $label;
        $permissions = array_values(array_filter(array_map(trim(...), explode(',', (string) $input->option('permissions'))), static fn (string $permission): bool => $permission !== ''));
        $role = $roles->create(null, $name, $label, $permissions);

        return [sprintf('Created the role %s (%s). Permissions: %s.', $role->name, $role->label, self::names($role->permissions))];
    }
}
