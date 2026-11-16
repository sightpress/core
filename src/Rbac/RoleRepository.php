<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\Rbac\Contract\PermissionHolders;
use SightPress\Core\Rbac\Contract\PermissionLookup;
use SightPress\Core\Rbac\Entity\Permission;
use SightPress\Core\Rbac\Entity\Role;
use SightPress\Core\Rbac\Entity\RoleAssignment;

/**
 * The site's roles, stored permissions and role assignments.
 */
final readonly class RoleRepository implements PermissionLookup, PermissionHolders
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findRole(string $name): ?Role
    {
        return $this->entityManager->getRepository(Role::class)->findOneBy(['name' => $name]);
    }

    /**
     * @return list<Role> ordered by name
     */
    public function roles(): array
    {
        return array_values($this->entityManager->getRepository(Role::class)->findBy([], ['name' => 'ASC']));
    }

    public function findPermission(string $name): ?Permission
    {
        return $this->entityManager->find(Permission::class, $name);
    }

    /**
     * @return list<Permission> ordered by name
     */
    public function permissions(): array
    {
        return array_values($this->entityManager->getRepository(Permission::class)->findBy([], ['name' => 'ASC']));
    }

    /**
     * @return list<Role> the roles a user has, ordered by name
     */
    public function rolesOf(int $userId): array
    {
        /** @var list<Role> $roles */
        $roles = $this->entityManager->createQueryBuilder()
            ->select('r', 'p')
            ->from(Role::class, 'r')
            ->join(RoleAssignment::class, 'a', 'WITH', 'a.role = r')
            ->leftJoin('r.permissions', 'p')
            ->where('a.userId = :user')
            ->setParameter('user', $userId)
            ->orderBy('r.name')
            ->getQuery()
            ->getResult();

        return $roles;
    }

    /**
     * The roles of several users in one query.
     *
     * @param list<int> $userIds
     *
     * @return array<int, list<Role>> by user id, each list ordered by name; users without a role are left out
     */
    public function rolesOfUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        /** @var list<RoleAssignment> $assignments */
        $assignments = $this->entityManager->createQueryBuilder()
            ->select('a', 'r')
            ->from(RoleAssignment::class, 'a')
            ->join('a.role', 'r')
            ->where('a.userId IN (:users)')
            ->setParameter('users', array_values(array_unique($userIds)))
            ->orderBy('a.userId')
            ->addOrderBy('r.name')
            ->getQuery()
            ->getResult();

        $roles = [];
        foreach ($assignments as $assignment) {
            $roles[$assignment->userId()][] = $assignment->role();
        }

        return $roles;
    }

    /**
     * Names of every permission the user holds through any of their roles, sorted.
     *
     * @return list<string>
     */
    public function permissionNamesOf(int $userId): array
    {
        $names = [];
        foreach ($this->rolesOf($userId) as $role) {
            $names = [...$names, ...$role->permissionNames()];
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    public function userIdsWith(string $permission): array
    {
        /** @var list<array{userId: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT a.userId')
            ->from(RoleAssignment::class, 'a')
            ->join('a.role', 'r')
            ->join('r.permissions', 'p')
            ->where('p.name = :permission')
            ->setParameter('permission', $permission)
            ->orderBy('a.userId')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['userId'], $rows);
    }

    /**
     * Gives the user exactly this role, replacing any other.
     */
    public function assign(int $userId, Role $role): void
    {
        foreach ($this->entityManager->getRepository(RoleAssignment::class)->findBy(['userId' => $userId]) as $old) {
            $this->entityManager->remove($old);
        }
        $this->entityManager->flush();

        $this->entityManager->persist(new RoleAssignment($userId, $role));
        $this->entityManager->flush();
    }

    public function countUsersWith(Role $role): int
    {
        return $this->entityManager->getRepository(RoleAssignment::class)->count(['role' => $role]);
    }

    public function save(Role|Permission ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function remove(Role $role): void
    {
        $this->entityManager->remove($role);
        $this->entityManager->flush();
    }
}
