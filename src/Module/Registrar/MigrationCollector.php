<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Migration\MigrationProvider;
use SightPress\Module\Registrar\MigrationRegistrar;

/**
 * Records where a module's migrations are; each module has its own namespace.
 */
final readonly class MigrationCollector implements MigrationRegistrar
{
    private const string NAMESPACE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(MigrationProvider $provider): void
    {
        if (preg_match(self::NAMESPACE_PATTERN, $provider->namespace()) !== 1) {
            throw new ModuleException(sprintf(
                'Module "%s" registers migrations in "%s", which is not a valid PHP namespace.',
                $this->module,
                $provider->namespace(),
            ));
        }
        if (!is_dir($provider->directory())) {
            throw new ModuleException(sprintf(
                'Module "%s" registers migrations in %s, which is not a folder.',
                $this->module,
                $provider->directory(),
            ));
        }

        $this->registry->addMigrations($this->module, $provider);
    }
}
