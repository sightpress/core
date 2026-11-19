<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use SightPress\Core\Module\ModuleRegistry;

/**
 * Which modules are on for the site whose container built it (ADR-0034), for modules that show something only while
 * another module is on (for example the page editor's "Leave out of site search" while search is on). Shared code,
 * so modules don't need the module loader.
 */
final readonly class EnabledModules
{
    public function __construct(private ModuleRegistry $registry)
    {
    }

    public function has(string $module): bool
    {
        return $this->registry->has($module);
    }
}
