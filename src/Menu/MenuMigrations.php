<?php

declare(strict_types=1);

namespace SightPress\Core\Menu;

use SightPress\Module\Migration\MigrationProvider;

/**
 * Where the menu module's migrations are.
 */
final readonly class MenuMigrations implements MigrationProvider
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
