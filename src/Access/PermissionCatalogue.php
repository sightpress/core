<?php

declare(strict_types=1);

namespace SightPress\Core\Access;

use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Access\Permission;

/**
 * Every permission the modules that are on registered, with the module that registered each one. The RBAC module
 * copies it into each site's database and checks the sign-up guardrail (ADR-0014) against it.
 */
final readonly class PermissionCatalogue
{
    /**
     * @param array<string, array{module: string, permission: Permission}> $permissions keyed by permission name
     */
    public function __construct(private array $permissions = [])
    {
    }

    public static function fromRegistry(ModuleRegistry $registry): self
    {
        $permissions = [];
        foreach ($registry->permissions() as $registered) {
            $permissions[$registered->item->name] = [
                'module' => $registered->module,
                'permission' => $registered->item,
            ];
        }

        return new self($permissions);
    }

    public function has(string $name): bool
    {
        return isset($this->permissions[$name]);
    }

    public function get(string $name): ?Permission
    {
        return $this->permissions[$name]['permission'] ?? null;
    }

    /**
     * Id of the module that registered the permission, or null when no module that is on did.
     */
    public function moduleOf(string $name): ?string
    {
        return $this->permissions[$name]['module'] ?? null;
    }

    /**
     * @return list<Permission>
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $entry): Permission => $entry['permission'],
            $this->permissions,
        ));
    }
}
