<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use SightPress\Module\Migration\MigrationProvider;

/**
 * Where the user module's migrations are.
 */
final readonly class UserMigrations implements MigrationProvider
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
