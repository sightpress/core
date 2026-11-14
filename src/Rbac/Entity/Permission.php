<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Module\Access\Permission as PermissionDefinition;

/**
 * A permission as stored in the site's database: a copy of what a module registered, kept in step with the
 * catalogue by the RBAC seeder, so roles can refer to it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permissions')]
final class Permission
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $label;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $description;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $module;

    #[ORM\Column(name: 'allowed_on_sign_up', type: Types::BOOLEAN)]
    private bool $allowedOnSignUp;

    public function __construct(PermissionDefinition $definition, string $module)
    {
        $this->name = $definition->name;
        $this->label = $definition->label;
        $this->description = $definition->description;
        $this->module = $module;
        $this->allowedOnSignUp = $definition->allowedOnSignUp;
    }

    /**
     * Copies the label, description, module and sign-up flag from the module's current definition.
     */
    public function update(PermissionDefinition $definition, string $module): void
    {
        $this->label = $definition->label;
        $this->description = $definition->description;
        $this->module = $module;
        $this->allowedOnSignUp = $definition->allowedOnSignUp;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function module(): string
    {
        return $this->module;
    }

    public function allowedOnSignUp(): bool
    {
        return $this->allowedOnSignUp;
    }
}
