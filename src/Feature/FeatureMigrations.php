<?php

declare(strict_types=1);

namespace SightPress\Core\Feature;

use SightPress\Module\Migration\MigrationProvider;

/**
 * Where the features module's migrations are.
 */
final readonly class FeatureMigrations implements MigrationProvider
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
