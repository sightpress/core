<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Module\Migration\MigrationProvider;

/**
 * Where the page module's migrations are.
 */
final readonly class PageMigrations implements MigrationProvider
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
