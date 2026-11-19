<?php

declare(strict_types=1);

namespace SightPress\Core\Search;

use SightPress\Module\Migration\MigrationProvider;

/**
 * The search module's tables: the index of the site's documents (ADR-0011).
 */
final readonly class SearchMigrations implements MigrationProvider
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
