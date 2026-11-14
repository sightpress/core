<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Migration\MigrationProvider;

/**
 * Migrations of a test module.
 */
final readonly class ExampleMigrations implements MigrationProvider
{
    /**
     * @param non-empty-string $namespace
     * @param non-empty-string $directory
     */
    public function __construct(
        private string $namespace,
        private string $directory,
    ) {
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function directory(): string
    {
        return $this->directory;
    }
}
