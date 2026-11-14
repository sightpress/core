<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ViewPath;
use SightPress\Module\Registrar\ViewRegistrar;

/**
 * Records a module's template folders; the template engine looks them up as `@<namespace>/<file>`.
 */
final readonly class ViewCollector implements ViewRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(string $namespace, string $directory): void
    {
        if (preg_match('/^[a-z0-9_]+$/', $namespace) !== 1) {
            throw new ModuleException(sprintf(
                'Module "%s" registers views under "%s": use lower-case letters, digits and "_".',
                $this->module,
                $namespace,
            ));
        }
        if (!str_starts_with($directory, '/') || !is_dir($directory)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers views in %s, which is not an absolute path to a folder.',
                $this->module,
                $directory,
            ));
        }

        $this->registry->addView($this->module, new ViewPath($namespace, $directory));
    }
}
