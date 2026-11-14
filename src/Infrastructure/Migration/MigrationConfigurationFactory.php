<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Migration;

use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use SightPress\Core\Module\ModuleRegistry;

/**
 * Builds the migrations configuration for a site: one migrations folder per module namespace, so versions of
 * different modules never clash. Every migration runs in a transaction where the database supports it.
 */
final readonly class MigrationConfigurationFactory
{
    public const string VERSIONS_TABLE = 'migration_versions';

    public function create(ModuleRegistry $registry): Configuration
    {
        $configuration = new Configuration();
        foreach ($registry->migrations() as $registered) {
            $configuration->addMigrationsDirectory($registered->item->namespace(), $registered->item->directory());
        }

        $storage = new TableMetadataStorageConfiguration();
        $storage->setTableName(self::VERSIONS_TABLE);
        $configuration->setMetadataStorageConfiguration($storage);
        $configuration->setTransactional(true);
        $configuration->setCheckDatabasePlatform(false);

        return $configuration;
    }
}
