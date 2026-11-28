<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Privacy;

use SightPress\Module\Migration\MigrationProvider;

/**
 * A module table with a user id, for the inventory check's tests.
 */
final readonly class CanaryMigrations implements MigrationProvider
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
