<?php

declare(strict_types=1);

namespace SightPress\Core\People\Command;

use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:role:list <slug>`: each role with its label, how many people have it and its permissions (those of features
 * that are on), and which roles are built in or the sign-up role.
 */
final readonly class ListRolesCommand extends RoleCommand
{
    public function name(): string
    {
        return 'site:role:list';
    }

    public function description(): string
    {
        return 'Lists the roles of a site, with their permissions and how many people have each one.';
    }

    public function parameters(): array
    {
        return [new CommandParameter('slug', 'Slug of the site:', required: true)];
    }

    protected function change(Roles $roles, CommandInput $input): array
    {
        $lines = [];
        foreach ($roles->roles() as $role) {
            $notes = array_filter([
                $role->fixed ? 'built in, can\'t be changed' : null,
                $role->signUp ? 'role of people who sign up' : null,
            ]);
            $lines[] = sprintf(
                '%s (%s): %d %s%s. Permissions: %s.',
                $role->name,
                $role->label,
                $role->people,
                $role->people === 1 ? 'person' : 'people',
                $notes === [] ? '' : '; ' . implode('; ', $notes),
                $role->name === 'admin' ? 'all' : self::names($role->permissions),
            );
        }

        return $lines;
    }
}
