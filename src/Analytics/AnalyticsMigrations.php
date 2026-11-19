<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use SightPress\Module\Migration\MigrationProvider;

/**
 * The analytics module's tables: today's raw hits, the daily figures and the daily salts (ADR-0012).
 */
final readonly class AnalyticsMigrations implements MigrationProvider
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
