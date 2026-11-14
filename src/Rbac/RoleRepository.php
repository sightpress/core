<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\Rbac\Entity\Permission;
use SightPress\Core\Rbac\Entity\Role;
use SightPress\Core\Rbac\Entity\RoleAssignment;

/**
 * The site's roles, stored permissions and role assignments.
 */
final readonly class RoleRepository
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
