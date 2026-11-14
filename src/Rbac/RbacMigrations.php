<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Module\Migration\MigrationProvider;

/**
 * Where the RBAC module's migrations are.
 */
final readonly class RbacMigrations implements MigrationProvider
{
    public function namespace(): string
    {
        return __NAMESPACE__ . '\Migrations';
    }

    public function directory(): string
    {
        return __DIR__ . '/Migrations';
    }
}
