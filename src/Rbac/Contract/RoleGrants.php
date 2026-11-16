<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * Who may give which role (D3), and giving it. People who manage users may give any role; people who may only
 * invite give the contributor role.
 */
interface RoleGrants
{
    /**
     * The roles this person may give, most powerful first.
     *
     * @return list<RoleChoice>
     */
    public function grantableBy(Actor $actor): array;

    public function canGrant(Actor $actor, string $role): bool;

    /**
     * Gives the user exactly this role, replacing any other.
     *
     * @throws \InvalidArgumentException when there is no such role
     */
    public function assign(int $userId, string $role): void;

    /**
     * The role with this name, or null when there is none.
     */
    public function find(string $role): ?RoleChoice;

    /**
     * The user's role, or null when they have none.
     */
    public function roleOf(int $userId): ?RoleChoice;
}
