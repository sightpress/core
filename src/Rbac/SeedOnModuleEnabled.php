<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Core\Tenant\SiteModuleChanged;

/**
 * When a site turns a module on, its permissions are stored and given to the admin role and the module's default
 * roles ({@see RbacSeeder}), as on a new site.
 */
final readonly class SeedOnModuleEnabled
{
    public function __construct(private RbacSeeder $seeder)
    {
    }

    public function __invoke(SiteModuleChanged $event): void
    {
        if ($event->change === SiteModuleChanged::ENABLED) {
            $this->seeder->seed();
        }
    }
}
