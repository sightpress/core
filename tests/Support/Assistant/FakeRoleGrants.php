<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

use LogicException;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\RoleChoice;
use SightPress\Core\Rbac\Contract\RoleGrants;

/**
 * Each account's role, for tests that only ask who has which role.
 */
final readonly class FakeRoleGrants implements RoleGrants
{
    /**
     * @param array<int, string> $roles user id => role
     */
    public function __construct(private array $roles = [])
    {
    }

    public function grantableBy(Actor $actor): array
    {
        return [];
    }

    public function canGrant(Actor $actor, string $role): bool
    {
        return false;
    }

    public function assign(int $userId, string $role): void
    {
        throw new LogicException('Not in tests.');
    }

    public function find(string $role): RoleChoice
    {
        return new RoleChoice($role, ucfirst($role), '');
    }

    public function roleOf(int $userId): ?RoleChoice
    {
        return isset($this->roles[$userId]) ? $this->find($this->roles[$userId]) : null;
    }

    public function rolesOf(array $userIds): array
    {
        return [];
    }

    public function change(Actor $actor, int $userId, string $role): void
    {
        throw new LogicException('Not in tests.');
    }
}
