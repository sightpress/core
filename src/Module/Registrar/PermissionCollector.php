<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Access\Permission;
use SightPress\Module\Registrar\PermissionRegistrar;

/**
 * Records a module's permissions for the site's permission catalogue.
 */
final readonly class PermissionCollector implements PermissionRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(Permission $permission): void
    {
        $this->registry->addPermission($this->module, $permission);
    }
}
