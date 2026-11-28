<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Rbac\Privacy\RoleData;
use SightPress\Module\Privacy\PersonalData;

/**
 * Gives a user a role on the site. Users are referred to by id only, so the RBAC module doesn't depend on the user
 * module's entities.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_role')]
final class RoleAssignment
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::INTEGER)]
    #[PersonalData(RoleData::KEY)]
    private int $userId;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    public function __construct(int $userId, Role $role)
    {
        $this->userId = $userId;
        $this->role = $role;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function role(): Role
    {
        return $this->role;
    }
}
