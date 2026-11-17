<?php

declare(strict_types=1);

namespace SightPress\Core\Audit;

use SightPress\Module\Migration\MigrationProvider;

final readonly class AuditMigrations implements MigrationProvider
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
