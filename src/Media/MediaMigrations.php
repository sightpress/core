<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Module\Migration\MigrationProvider;

/**
 * Where the media module's migrations are.
 */
final readonly class MediaMigrations implements MigrationProvider
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
