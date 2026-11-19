<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use SightPress\Module\Migration\MigrationProvider;

/**
 * The assistant module's table: what each use of the assistant cost (ADR-0040).
 */
final readonly class AssistantMigrations implements MigrationProvider
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
