<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Rbac\RbacException;

/**
 * A named set of permissions on one site, for example `editor`.
 *
 * Change a role's permissions and name through {@see \SightPress\Core\Rbac\RoleManager}, which applies the
 * guardrails; the entity only checks its own shape.
 */
#[ORM\Entity]
#[ORM\Table(name: 'roles')]
final class Role
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50, unique: true)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $label;

    /** Column `is_system`: `system` is a reserved word on MySQL. */
    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN)]
    private bool $system;

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class)]
    #[ORM\JoinTable(name: 'role_permission')]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'permission_name', referencedColumnName: 'name', onDelete: 'CASCADE')]
    private Collection $permissions;

    /**
     * @param bool $system system roles (`admin`, `member`) can't be renamed or deleted
     */
    public function __construct(string $name, string $label, bool $system = false)
    {
        $this->name = self::checkName($name);
        $this->label = self::checkLabel($label);
        $this->system = $system;
        $this->permissions = new ArrayCollection();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_values($this->permissions->toArray());
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        $names = array_map(static fn (Permission $permission): string => $permission->name(), $this->permissions());
        sort($names);

        return $names;
    }

    public function hasPermission(string $name): bool
    {
        return in_array($name, $this->permissionNames(), true);
    }

    /**
     * @internal use {@see \SightPress\Core\Rbac\RoleManager}
     */
    public function addPermission(Permission $permission): void
    {
        if (!$this->hasPermission($permission->name())) {
            $this->permissions->add($permission);
        }
    }

    /**
     * @internal use {@see \SightPress\Core\Rbac\RoleManager}
     */
    public function removePermission(string $name): void
    {
        foreach ($this->permissions as $key => $permission) {
            if ($permission->name() === $name) {
                $this->permissions->remove($key);
            }
        }
    }

    /**
     * @internal use {@see \SightPress\Core\Rbac\RoleManager}
     */
    public function rename(string $name, string $label): void
    {
        $this->name = self::checkName($name);
        $this->label = self::checkLabel($label);
    }

    private static function checkName(string $name): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || strlen($name) > 50) {
            throw new RbacException(sprintf(
                'Role name "%s" must be 1 to 50 lower-case letters, digits and "_", starting with a letter.',
                $name,
            ));
        }

        return $name;
    }

    private static function checkLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 100) {
            throw new RbacException('A role label must be 1 to 100 characters.');
        }

        return $label;
    }
}
