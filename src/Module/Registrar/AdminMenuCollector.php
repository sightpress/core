<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\Registrar\AdminMenuRegistrar;

/**
 * Records a module's admin navigation entries.
 */
final readonly class AdminMenuCollector implements AdminMenuRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(AdminMenuItem $item): void
    {
        $this->registry->addAdminMenuItem($this->module, $item);
    }
}
