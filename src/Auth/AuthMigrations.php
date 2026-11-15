<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Module\Migration\MigrationProvider;

/**
 * Where the auth module's migrations are.
 */
final readonly class AuthMigrations implements MigrationProvider
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
