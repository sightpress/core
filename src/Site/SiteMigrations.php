<?php

declare(strict_types=1);

namespace SightPress\Core\Site;

use SightPress\Module\Migration\MigrationProvider;

/**
 * Where the site module's migrations are.
 */
final readonly class SiteMigrations implements MigrationProvider
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
