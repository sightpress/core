<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

use SightPress\Core\Template\Message;

/**
 * The site's roles, to list, create, change and delete them under every RBAC rule (X-4): `admin` and `member` can't
 * be changed; only permissions of features that are on can be given or taken away (the others are kept); the
 * sign-up role only gets permissions that are safe on sign-up; nobody takes away their own last way to manage roles;
 * a role that people have, or the sign-up role, can't be deleted. Changes are announced with {@see RoleEdited}.
 * Roles are named by their `name`; null actors are the command line.
 */
interface Roles
{
    public const string PERMISSION = 'role.manage';

    /**
     * The seeded roles from most to least powerful, then the others by name.
     *
     * @return list<RoleSummary>
     */
    public function roles(): array;

    public function find(string $name): ?RoleSummary;

    /**
     * The permissions that can be given (those of features that are on), by name.
     *
     * @return list<PermissionSummary>
     */
    public function permissions(): array;

    /**
     * A free role name made from a label: "Shop staff" becomes `shop_staff`.
     */
    public function nameFor(string $label): string;

    /**
     * What is wrong with a new role (`$role` null) or with these changes, keyed like {@see RoleRefused::$problems}.
     *
     * @param list<string> $permissions
     *
     * @return array<string, Message>
     */
    public function problems(?Actor $actor, ?string $role, string $name, string $label, array $permissions): array;

    /**
     * @param list<string> $permissions
     *
     * @throws RoleRefused
     */
    public function create(?Actor $actor, string $name, string $label, array $permissions): RoleSummary;

    /**
     * Gives the role a new label and exactly these permissions (of those that can be given); says whether anything
     * changed.
     *
     * @param list<string> $permissions
     *
     * @throws RoleRefused
     */
    public function update(?Actor $actor, string $role, string $label, array $permissions): bool;

    /**
     * @throws RoleRefused
     */
    public function grant(?Actor $actor, string $role, string $permission): bool;

    /**
     * @throws RoleRefused
     */
    public function revoke(?Actor $actor, string $role, string $permission): bool;

    /**
     * Why the role can't be deleted, or null when it can.
     */
    public function deleteProblem(string $role): ?Message;

    /**
     * @throws RoleRefused
     */
    public function delete(?Actor $actor, string $role): void;
}
